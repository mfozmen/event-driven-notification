<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Enums\Status;
use App\Events\NotificationStatusUpdated;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use App\Services\CircuitBreaker;
use App\Services\RetryStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::spy();

    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);

    $this->retryStrategy = Mockery::mock(RetryStrategy::class);

    $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $this->circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $this->circuitBreaker->shouldReceive('recordSuccess')->andReturnNull();
    $this->circuitBreaker->shouldReceive('recordFailure')->andReturnNull();
});

test('delivering a notification fires NotificationStatusUpdated with status delivered', function () {
    Event::fake(NotificationStatusUpdated::class);

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    Event::assertDispatched(NotificationStatusUpdated::class, function (NotificationStatusUpdated $event) use ($notification) {
        return $event->notification->id === $notification->id
            && $event->notification->status === Status::DELIVERED;
    });
});

test('cancelling a notification fires NotificationStatusUpdated with status cancelled', function () {
    Event::fake(NotificationStatusUpdated::class);

    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $this->patchJson("/api/notifications/{$notification->id}/cancel");

    Event::assertDispatched(NotificationStatusUpdated::class, function (NotificationStatusUpdated $event) use ($notification) {
        return $event->notification->id === $notification->id
            && $event->notification->status === Status::CANCELLED;
    });
});

test('failed delivery fires NotificationStatusUpdated with status retrying', function () {
    Event::fake(NotificationStatusUpdated::class);

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $this->retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'max_attempts' => 3,
    ]);

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])
        ->makePartial();
    $job->shouldReceive('release')->with(30)->once();

    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    Event::assertDispatched(NotificationStatusUpdated::class, function (NotificationStatusUpdated $event) use ($notification) {
        return $event->notification->id === $notification->id
            && $event->notification->status === Status::RETRYING;
    });
});

test('permanently failed delivery fires NotificationStatusUpdated with status permanently_failed', function () {
    Event::fake(NotificationStatusUpdated::class);

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Bad request', false));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(false);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    Event::assertDispatched(NotificationStatusUpdated::class, function (NotificationStatusUpdated $event) use ($notification) {
        return $event->notification->id === $notification->id
            && $event->notification->status === Status::PERMANENTLY_FAILED;
    });
});

test('queuing a notification fires NotificationStatusUpdated with status queued', function () {
    Event::fake(NotificationStatusUpdated::class);

    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $listener = app(\App\Listeners\QueueNotificationListener::class);
    $listener->handle(new \App\Events\NotificationCreated($notification));

    Event::assertDispatched(NotificationStatusUpdated::class, function (NotificationStatusUpdated $event) use ($notification) {
        return $event->notification->id === $notification->id
            && $event->notification->status === Status::QUEUED;
    });
});

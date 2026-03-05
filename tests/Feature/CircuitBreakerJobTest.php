<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use App\Services\CircuitBreaker;
use App\Services\RetryStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $this->factory = Mockery::mock(ChannelProviderFactory::class);
    $this->factory->shouldReceive('resolve')->andReturn($provider);

    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);

    $this->retryStrategy = Mockery::mock(RetryStrategy::class);
});

test('job releases back to queue when circuit is open', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')
        ->with(Channel::SMS)
        ->andReturn(false);

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])
        ->makePartial();
    $job->shouldReceive('release')->with(30)->once();

    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);
});

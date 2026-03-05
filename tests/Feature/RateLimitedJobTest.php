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
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::spy();

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $this->factory = Mockery::mock(ChannelProviderFactory::class);
    $this->factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy = Mockery::mock(RetryStrategy::class);

    $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $this->circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $this->circuitBreaker->shouldReceive('recordSuccess')->andReturnNull();
    $this->circuitBreaker->shouldReceive('recordFailure')->andReturnNull();
});

test('job is released back to queue when rate limited', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $limiter = $this->mock(ChannelRateLimiter::class);
    $limiter->shouldReceive('attempt')
        ->with(Channel::SMS)
        ->andReturn(false);

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])
        ->makePartial();
    $job->shouldReceive('release')->with(1)->once();

    $job->handle($limiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);
});

test('job processes notification when rate limit allows', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $limiter = $this->mock(ChannelRateLimiter::class);
    $limiter->shouldReceive('attempt')
        ->with(Channel::SMS)
        ->andReturn(true);

    $job = new SendNotificationJob($notification->id);
    $job->handle($limiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

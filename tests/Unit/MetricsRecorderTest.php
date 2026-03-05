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
    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);

    $this->retryStrategy = Mockery::mock(RetryStrategy::class);

    $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $this->circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $this->circuitBreaker->shouldReceive('recordSuccess')->andReturnNull();
    $this->circuitBreaker->shouldReceive('recordFailure')->andReturnNull();
});

test('counter is incremented on successful delivery', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    Redis::shouldReceive('incr')->with('metrics:deliveries:success:sms')->once();
    Redis::shouldReceive('expire')->with('metrics:deliveries:success:sms', 3600)->once();
    Redis::shouldReceive('lpush')->with('metrics:latency:sms', Mockery::type('float'))->once();
    Redis::shouldReceive('ltrim')->with('metrics:latency:sms', 0, 99)->once();

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);
});

test('counter is incremented on failed delivery', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $this->retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::EMAIL,
        'max_attempts' => 3,
    ]);

    Redis::shouldReceive('incr')->with('metrics:deliveries:failure:email')->once();
    Redis::shouldReceive('expire')->with('metrics:deliveries:failure:email', 3600)->once();
    Redis::shouldReceive('lpush')->with('metrics:latency:email', Mockery::type('float'))->once();
    Redis::shouldReceive('ltrim')->with('metrics:latency:email', 0, 99)->once();

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])->makePartial();
    $job->shouldReceive('release')->with(30)->once();

    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);
});

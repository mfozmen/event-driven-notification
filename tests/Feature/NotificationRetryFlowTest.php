<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
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
});

test('retryable failure sets status to retrying and re-queues', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Connection timeout', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $rateLimiter->shouldReceive('attempt')->andReturn(true);

    $retryStrategy = new RetryStrategy;

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $circuitBreaker->shouldReceive('recordFailure')->andReturnNull();

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->next_retry_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Connection timeout');
    expect($notification->attempts)->toBe(1);
});

test('non-retryable failure sets permanently_failed immediately', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Invalid recipient', false));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $rateLimiter->shouldReceive('attempt')->andReturn(true);

    $retryStrategy = new RetryStrategy;

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $circuitBreaker->shouldReceive('recordFailure')->andReturnNull();

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PERMANENTLY_FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Invalid recipient');
});

test('notification retries up to max_attempts then permanently fails', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $rateLimiter->shouldReceive('attempt')->andReturn(true);

    $retryStrategy = new RetryStrategy;

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $circuitBreaker->shouldReceive('recordFailure')->andReturnNull();

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    // Attempt 1: should retry
    $job1 = new SendNotificationJob($notification->id);
    $job1->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);
    $notification->refresh();
    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->attempts)->toBe(1);

    // Simulate re-queue: status stays retrying, job picks it up again
    // Attempt 2: should retry
    $job2 = new SendNotificationJob($notification->id);
    $job2->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);
    $notification->refresh();
    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->attempts)->toBe(2);

    // Attempt 3: max reached, should permanently fail
    $job3 = new SendNotificationJob($notification->id);
    $job3->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);
    $notification->refresh();
    expect($notification->status)->toBe(Status::PERMANENTLY_FAILED);
    expect($notification->attempts)->toBe(3);
    expect($notification->failed_at)->not->toBeNull();
});

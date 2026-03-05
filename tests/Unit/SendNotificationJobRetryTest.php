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

test('handle retries retryable failure when attempts remaining', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $this->retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])
        ->makePartial();
    $job->shouldReceive('release')->with(30)->once();

    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->next_retry_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Server error');
});

test('handle sets permanently_failed when max attempts reached', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(false);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 2,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PERMANENTLY_FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Server error');
});

test('handle sets permanently_failed for non-retryable failure', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Bad request', false));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(false);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PERMANENTLY_FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Bad request');
});

test('handle processes notification in retrying status', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldNotReceive('shouldRetry');

    $notification = Notification::factory()->create([
        'status' => Status::RETRYING,
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
    expect($notification->delivered_at)->not->toBeNull();
    expect($notification->attempts)->toBe(2);
});

test('handle permanently fails on first attempt when max_attempts is 1', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $this->retryStrategy->shouldReceive('shouldRetry')->andReturn(false);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'max_attempts' => 1,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PERMANENTLY_FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->attempts)->toBe(1);
});

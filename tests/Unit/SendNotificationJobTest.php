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

    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);

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

test('handle performs atomic status transition from queued to delivered', function () {
    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle skips notification that is not in queued status', function () {
    $notification = Notification::factory()->create(['status' => Status::PROCESSING]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle skips notification that is already delivered', function () {
    $notification = Notification::factory()->create(['status' => Status::DELIVERED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle skips notification that was cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::CANCELLED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::CANCELLED);
});

test('handle gracefully handles non-existent notification', function () {
    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldNotReceive('resolve');

    $job = new SendNotificationJob('non-existent-id');
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);
});

test('handle does not call provider when another worker already claimed the notification', function () {
    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    // Simulate another worker claiming the notification between the status check and the atomic UPDATE
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldNotReceive('send');

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldNotReceive('resolve');

    // Change status to processing before the job's atomic UPDATE runs
    Notification::where('id', $notification->id)->update(['status' => Status::PROCESSING]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle sets status to retrying when provider returns retryable failure', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $retryStrategy = Mockery::mock(RetryStrategy::class);
    $retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->next_retry_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Server error');
    expect($notification->attempts)->toBe(1);
});

test('handle sets status to retrying when provider throws unexpected exception', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andThrow(new \RuntimeException('Unexpected failure'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $retryStrategy = Mockery::mock(RetryStrategy::class);
    $retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'max_attempts' => 3,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);
    expect($notification->next_retry_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Unexpected failure');
});

test('handle increments attempts and sets last_attempted_at on processing', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'last_attempted_at' => null,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $this->circuitBreaker);

    $notification->refresh();

    expect($notification->attempts)->toBe(1);
    expect($notification->last_attempted_at)->not->toBeNull();
});

test('handle calls recordSuccess on circuit breaker after successful delivery', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->with(Channel::SMS)->andReturn(true);
    $circuitBreaker->shouldReceive('recordSuccess')->with(Channel::SMS)->once();

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory, $this->retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle calls recordFailure on circuit breaker after failed delivery', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $retryStrategy = Mockery::mock(RetryStrategy::class);
    $retryStrategy->shouldReceive('shouldRetry')->andReturn(true);
    $retryStrategy->shouldReceive('calculateDelay')->andReturn(30);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
        'max_attempts' => 3,
    ]);

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->with(Channel::SMS)->andReturn(true);
    $circuitBreaker->shouldReceive('recordFailure')->with(Channel::SMS)->once();

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory, $retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);
});

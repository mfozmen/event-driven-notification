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
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('stuck retrying notification gets re-dispatched and delivered', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::RETRYING,
        'next_retry_at' => now()->subMinute(),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    // Run the safety net command
    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();
    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class);

    // Now simulate the job running with a successful provider
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $rateLimiter->shouldReceive('attempt')->andReturn(true);

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $circuitBreaker->shouldReceive('recordSuccess')->andReturnNull();

    $retryStrategy = new RetryStrategy;

    $job = new SendNotificationJob($notification->id);
    $job->handle($rateLimiter, $factory, $retryStrategy, $circuitBreaker);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
    expect($notification->attempts)->toBe(2);
    expect($notification->delivered_at)->not->toBeNull();
});

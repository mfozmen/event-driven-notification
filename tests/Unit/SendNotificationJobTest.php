<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $this->factory = Mockery::mock(ChannelProviderFactory::class);
    $this->factory->shouldReceive('resolve')->andReturn($provider);
});

test('handle performs atomic status transition from queued to delivered', function () {
    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle skips notification that is not in queued status', function () {
    $notification = Notification::factory()->create(['status' => Status::PROCESSING]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle skips notification that is already delivered', function () {
    $notification = Notification::factory()->create(['status' => Status::DELIVERED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle skips notification that was cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::CANCELLED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::CANCELLED);
});

test('handle gracefully handles non-existent notification', function () {
    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldNotReceive('resolve');

    $job = new SendNotificationJob('non-existent-id');
    $job->handle($this->rateLimiter, $factory);
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
    $job->handle($this->rateLimiter, $factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle sets status to failed when provider returns failure', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::failure('Server error', true));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Server error');
    expect($notification->attempts)->toBe(1);
});

test('handle sets status to failed when provider throws unexpected exception', function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andThrow(new \RuntimeException('Unexpected failure'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->toBe('Unexpected failure');
});

test('handle increments attempts and sets last_attempted_at on processing', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'last_attempted_at' => null,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, $this->factory);

    $notification->refresh();

    expect($notification->attempts)->toBe(1);
    expect($notification->last_attempted_at)->not->toBeNull();
});

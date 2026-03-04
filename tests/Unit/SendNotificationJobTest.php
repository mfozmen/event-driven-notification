<?php

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
});

test('handle performs atomic status transition from queued to processing', function () {
    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle skips notification that is not in queued status', function () {
    $notification = Notification::factory()->create(['status' => Status::PROCESSING]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter);

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);
});

test('handle skips notification that is already delivered', function () {
    $notification = Notification::factory()->create(['status' => Status::DELIVERED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});

test('handle skips notification that was cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::CANCELLED]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter);

    $notification->refresh();

    expect($notification->status)->toBe(Status::CANCELLED);
});

test('handle gracefully handles non-existent notification', function () {
    $job = new SendNotificationJob('non-existent-id');
    $job->handle($this->rateLimiter);

    expect(true)->toBeTrue();
});

test('handle increments attempts and sets last_attempted_at on processing', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'attempts' => 0,
        'last_attempted_at' => null,
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter);

    $notification->refresh();

    expect($notification->attempts)->toBe(1);
    expect($notification->last_attempted_at)->not->toBeNull();
});
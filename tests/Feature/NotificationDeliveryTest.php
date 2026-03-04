<?php

use App\Channels\ChannelProviderFactory;
use App\DTOs\DeliveryResult;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
});

test('job delivers notification successfully via channel provider', function () {
    Http::fake([
        '*' => Http::response([
            'messageId' => 'ext-msg-001',
            'status' => 'accepted',
            'timestamp' => now()->toIso8601String(),
        ], 202),
    ]);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
        'recipient' => '+905551234567',
        'content' => 'Delivery test',
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, app(ChannelProviderFactory::class));

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
    expect($notification->delivered_at)->not->toBeNull();
    expect($notification->attempts)->toBe(1);
    expect($notification->last_attempted_at)->not->toBeNull();
});

test('job sets status to failed when provider returns 500', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Internal Server Error'], 500),
    ]);

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::EMAIL,
        'recipient' => 'user@example.com',
        'content' => 'Failure test',
    ]);

    $job = new SendNotificationJob($notification->id);
    $job->handle($this->rateLimiter, app(ChannelProviderFactory::class));

    $notification->refresh();

    expect($notification->status)->toBe(Status::FAILED);
    expect($notification->failed_at)->not->toBeNull();
    expect($notification->error_message)->not->toBeNull();
    expect($notification->attempts)->toBe(1);
    expect($notification->last_attempted_at)->not->toBeNull();
});

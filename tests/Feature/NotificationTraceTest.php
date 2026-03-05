<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Services\ChannelRateLimiter;
use App\Services\CircuitBreaker;
use App\Services\NotificationLogger;
use App\Services\RetryStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

test('creating a notification creates a created log entry', function () {
    Queue::fake();

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Trace test',
    ]);

    $response->assertStatus(201);

    $notificationId = $response->json('data.id');

    $log = NotificationLog::where('notification_id', $notificationId)
        ->where('event', 'created')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->correlation_id)->not->toBeNull();
});

test('delivery creates processing and delivered log entries', function () {
    Redis::spy();

    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $factory = Mockery::mock(ChannelProviderFactory::class);
    $factory->shouldReceive('resolve')->andReturn($provider);

    $rateLimiter = Mockery::mock(ChannelRateLimiter::class);
    $rateLimiter->shouldReceive('attempt')->andReturn(true);

    $circuitBreaker = Mockery::mock(CircuitBreaker::class);
    $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
    $circuitBreaker->shouldReceive('recordSuccess')->andReturnNull();

    $job = new SendNotificationJob($notification->id);
    $job->handle($rateLimiter, $factory, new RetryStrategy, $circuitBreaker);

    $events = NotificationLog::where('notification_id', $notification->id)
        ->orderBy('created_at')
        ->pluck('event')
        ->all();

    expect($events)->toContain('processing');
    expect($events)->toContain('delivered');
});

test('trace endpoint returns ordered entries for a notification', function () {
    $notification = Notification::factory()->create();

    $logger = app(NotificationLogger::class);
    $logger->log($notification, 'created');
    $logger->log($notification, 'queued');
    $logger->log($notification, 'processing');
    $logger->log($notification, 'delivered');

    $response = $this->getJson("/api/notifications/{$notification->id}/trace");

    $response->assertStatus(200)
        ->assertJsonCount(4, 'data');

    $events = collect($response->json('data'))->pluck('event')->all();
    expect($events)->toBe(['created', 'queued', 'processing', 'delivered']);
});

test('trace endpoint returns 404 for non-existent notification', function () {
    $response = $this->getJson('/api/notifications/non-existent-uuid/trace');

    $response->assertStatus(404);
});

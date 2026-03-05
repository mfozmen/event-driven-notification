<?php

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creating notification with future scheduled_at returns 201 and status stays pending', function () {
    Queue::fake();

    $scheduledAt = now()->addHour()->toIso8601String();

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Scheduled message',
        'scheduled_at' => $scheduledAt,
    ]);

    $response->assertStatus(201);

    $notificationId = $response->json('data.id');
    $notification = Notification::find($notificationId);

    expect($notification->status)->toBe(Status::PENDING);
    expect($notification->scheduled_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

test('creating notification with past scheduled_at gets queued immediately', function () {
    Queue::fake();

    $scheduledAt = now()->subHour()->toIso8601String();

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Past scheduled message',
        'scheduled_at' => $scheduledAt,
    ]);

    $response->assertStatus(201);

    $notificationId = $response->json('data.id');
    $notification = Notification::find($notificationId);

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notificationId) {
        return $job->notificationId === $notificationId;
    });
});

test('creating notification with null scheduled_at gets queued immediately', function () {
    Queue::fake();

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Immediate message',
    ]);

    $response->assertStatus(201);

    $notificationId = $response->json('data.id');
    $notification = Notification::find($notificationId);

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notificationId) {
        return $job->notificationId === $notificationId;
    });
});

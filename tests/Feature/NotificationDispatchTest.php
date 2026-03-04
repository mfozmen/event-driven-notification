<?php

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('store dispatches SendNotificationJob when notification is created', function () {
    Queue::fake();

    $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    Queue::assertPushed(SendNotificationJob::class);
});

test('store dispatches job to high queue for high priority notification', function () {
    Queue::fake();

    $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => 'high',
    ]);

    Queue::assertPushedOn('high', SendNotificationJob::class);
});

test('store dispatches job to normal queue for normal priority notification', function () {
    Queue::fake();

    $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    Queue::assertPushedOn('normal', SendNotificationJob::class);
});

test('store sets notification status to queued after dispatch', function () {
    Queue::fake();

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    $notification = Notification::find($response->json('data.id'));

    expect($notification->status->value)->toBe('queued');
});

test('store dispatches job to low queue for low priority notification', function () {
    Queue::fake();

    $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => 'low',
    ]);

    Queue::assertPushedOn('low', SendNotificationJob::class);
});

test('storeBatch dispatches SendNotificationJob for each notification in batch', function () {
    Queue::fake();

    $this->postJson('/api/notifications/batch', [
        'notifications' => [
            ['recipient' => '+905551234567', 'channel' => 'sms', 'content' => 'Hello 1'],
            ['recipient' => '+905551234568', 'channel' => 'email', 'content' => 'Hello 2'],
            ['recipient' => '+905551234569', 'channel' => 'push', 'content' => 'Hello 3'],
        ],
    ]);

    Queue::assertPushed(SendNotificationJob::class, 3);
});

test('store does not dispatch job for duplicate idempotency key', function () {
    Queue::fake();

    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'idempotency_key' => 'dedup-key',
    ];

    $this->postJson('/api/notifications', $payload);
    $this->postJson('/api/notifications', $payload);

    Queue::assertPushed(SendNotificationJob::class, 1);
});

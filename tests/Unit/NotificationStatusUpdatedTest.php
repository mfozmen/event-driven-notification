<?php

use App\Enums\Channel;
use App\Enums\Status;
use App\Events\NotificationStatusUpdated;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel as BroadcastChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('broadcastOn returns correct channel name with notification id', function () {
    $notification = Notification::factory()->create();

    $event = new NotificationStatusUpdated($notification);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(BroadcastChannel::class);
    expect($channels[0]->name)->toBe("notifications.{$notification->id}");
});

test('broadcastWith contains required fields', function () {
    $notification = Notification::factory()->create([
        'status' => Status::DELIVERED,
        'channel' => Channel::SMS,
        'attempts' => 2,
    ]);

    $event = new NotificationStatusUpdated($notification);

    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'status', 'attempts', 'updated_at']);
    expect($payload['id'])->toBe($notification->id);
    expect($payload['status'])->toBe('delivered');
    expect($payload['attempts'])->toBe(2);
    expect($payload['updated_at'])->toBe($notification->updated_at->toIso8601String());
});

test('broadcastAs returns correct event name', function () {
    $notification = Notification::factory()->create();

    $event = new NotificationStatusUpdated($notification);

    expect($event->broadcastAs())->toBe('notification.status.updated');
});

test('broadcastWith reflects current notification status', function () {
    $notification = Notification::factory()->create([
        'status' => Status::RETRYING,
        'attempts' => 1,
    ]);

    $event = new NotificationStatusUpdated($notification);
    $payload = $event->broadcastWith();

    expect($payload['status'])->toBe('retrying');
    expect($payload['attempts'])->toBe(1);
});

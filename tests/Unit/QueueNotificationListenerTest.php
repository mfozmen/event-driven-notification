<?php

use App\Enums\Status;
use App\Events\NotificationCreated;
use App\Jobs\SendNotificationJob;
use App\Listeners\QueueNotificationListener;
use App\Models\Notification;
use App\Services\NotificationLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('handle sets notification status to queued', function () {
    Queue::fake();
    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $listener = new QueueNotificationListener(app(NotificationLogger::class));
    $listener->handle(new NotificationCreated($notification));

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);
});

test('handle dispatches SendNotificationJob', function () {
    Queue::fake();
    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $listener = new QueueNotificationListener(app(NotificationLogger::class));
    $listener->handle(new NotificationCreated($notification));

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id;
    });
});

test('handle dispatches job to high queue for high priority', function () {
    Queue::fake();
    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'priority' => 'high',
    ]);

    $listener = new QueueNotificationListener(app(NotificationLogger::class));
    $listener->handle(new NotificationCreated($notification));

    Queue::assertPushedOn('high', SendNotificationJob::class);
});

test('handle dispatches job to normal queue for normal priority', function () {
    Queue::fake();
    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'priority' => 'normal',
    ]);

    $listener = new QueueNotificationListener(app(NotificationLogger::class));
    $listener->handle(new NotificationCreated($notification));

    Queue::assertPushedOn('normal', SendNotificationJob::class);
});

test('handle dispatches job to low queue for low priority', function () {
    Queue::fake();
    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'priority' => 'low',
    ]);

    $listener = new QueueNotificationListener(app(NotificationLogger::class));
    $listener->handle(new NotificationCreated($notification));

    Queue::assertPushedOn('low', SendNotificationJob::class);
});

test('handle does not throw when internal error occurs', function () {
    Queue::fake();
    Log::spy();

    $logger = Mockery::mock(NotificationLogger::class);
    $logger->shouldReceive('log')->andThrow(new \RuntimeException('DB connection lost'));

    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $listener = new QueueNotificationListener($logger);
    $listener->handle(new NotificationCreated($notification));

    Log::shouldHaveReceived('error')->once();
});

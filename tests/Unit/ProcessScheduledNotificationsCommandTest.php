<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('command picks up due notifications and queues them', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'priority' => Priority::HIGH,
        'scheduled_at' => now()->subMinutes(5),
    ]);

    $this->artisan('notifications:process-scheduled')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id
            && $job->queue === 'high';
    });
});

test('command ignores notifications with future scheduled_at', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'scheduled_at' => now()->addHour(),
    ]);

    $this->artisan('notifications:process-scheduled')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::PENDING);

    Queue::assertNothingPushed();
});

test('command ignores non-pending notifications', function () {
    Queue::fake();

    $statuses = [
        Status::QUEUED,
        Status::DELIVERED,
        Status::CANCELLED,
    ];

    $notifications = [];
    foreach ($statuses as $status) {
        $notifications[] = Notification::factory()->create([
            'status' => $status,
            'scheduled_at' => now()->subMinutes(5),
        ]);
    }

    $this->artisan('notifications:process-scheduled')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    foreach ($notifications as $index => $notification) {
        $notification->refresh();
        expect($notification->status)->toBe($statuses[$index]);
    }
});

test('command outputs count of processed notifications', function () {
    Queue::fake();

    Notification::factory()->count(3)->create([
        'status' => Status::PENDING,
        'scheduled_at' => now()->subMinutes(5),
    ]);

    $this->artisan('notifications:process-scheduled')
        ->expectsOutputToContain('Processed 3 scheduled notifications')
        ->assertSuccessful();
});

test('command processes notification with scheduled_at exactly equal to now', function () {
    Queue::fake();

    $this->freezeTime();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'scheduled_at' => now(),
    ]);

    $this->artisan('notifications:process-scheduled')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id;
    });
});

test('command outputs zero when no scheduled notifications exist', function () {
    Queue::fake();

    $this->artisan('notifications:process-scheduled')
        ->expectsOutputToContain('Processed 0 scheduled notifications')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('process-stuck does not pick up scheduled pending notifications with past scheduled_at', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'created_at' => now()->subMinutes(10),
        'scheduled_at' => now()->subMinutes(10),
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::PENDING);

    Queue::assertNothingPushed();
});

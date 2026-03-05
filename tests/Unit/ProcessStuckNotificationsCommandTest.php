<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('command re-dispatches retrying notifications with past next_retry_at', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::RETRYING,
        'priority' => Priority::HIGH,
        'next_retry_at' => now()->subMinutes(5),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id
            && $job->queue === 'high';
    });
});

test('command ignores retrying notifications with future next_retry_at', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::RETRYING,
        'next_retry_at' => now()->addMinutes(5),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::RETRYING);

    Queue::assertNothingPushed();
});

test('command ignores notifications in terminal statuses', function () {
    Queue::fake();

    $statuses = [
        Status::QUEUED,
        Status::DELIVERED,
        Status::PERMANENTLY_FAILED,
        Status::CANCELLED,
    ];

    $notifications = [];
    foreach ($statuses as $status) {
        $notifications[] = Notification::factory()->create([
            'status' => $status,
            'next_retry_at' => now()->subMinutes(5),
        ]);
    }

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    foreach ($notifications as $index => $notification) {
        $notification->refresh();
        expect($notification->status)->toBe($statuses[$index]);
    }
});

test('command outputs zero when no stuck notifications exist', function () {
    Queue::fake();

    $this->artisan('notifications:process-stuck')
        ->expectsOutputToContain('Processed 0 stuck notifications')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command outputs count of processed notifications', function () {
    Queue::fake();

    Notification::factory()->count(3)->create([
        'status' => Status::RETRYING,
        'next_retry_at' => now()->subMinutes(5),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('notifications:process-stuck')
        ->expectsOutputToContain('Processed 3 stuck notifications')
        ->assertSuccessful();
});

test('command re-dispatches processing notifications stuck for more than 5 minutes', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PROCESSING,
        'priority' => Priority::HIGH,
        'last_attempted_at' => now()->subMinutes(6),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id
            && $job->queue === 'high';
    });
});

test('command ignores processing notifications under 5 minutes old', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PROCESSING,
        'last_attempted_at' => now()->subMinutes(3),
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::PROCESSING);

    Queue::assertNothingPushed();
});

test('command re-dispatches pending notifications stuck for more than 2 minutes', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'priority' => Priority::NORMAL,
        'created_at' => now()->subMinutes(3),
        'scheduled_at' => null,
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);

    Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
        return $job->notificationId === $notification->id
            && $job->queue === 'normal';
    });
});

test('command ignores scheduled notifications in pending status', function () {
    Queue::fake();

    $notification = Notification::factory()->create([
        'status' => Status::PENDING,
        'created_at' => now()->subMinutes(10),
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $this->artisan('notifications:process-stuck')
        ->assertSuccessful();

    $notification->refresh();

    expect($notification->status)->toBe(Status::PENDING);

    Queue::assertNothingPushed();
});

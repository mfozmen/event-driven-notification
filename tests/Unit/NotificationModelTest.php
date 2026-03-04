<?php

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;

test('notification has correct fillable fields', function () {
    $notification = new Notification;

    expect($notification->getFillable())
        ->toContain('batch_id')
        ->toContain('idempotency_key')
        ->toContain('correlation_id')
        ->toContain('recipient')
        ->toContain('channel')
        ->toContain('content')
        ->toContain('priority')
        ->toContain('status')
        ->toContain('attempts')
        ->toContain('max_attempts')
        ->toContain('next_retry_at')
        ->toContain('last_attempted_at')
        ->toContain('delivered_at')
        ->toContain('failed_at')
        ->toContain('scheduled_at')
        ->toContain('error_message');
});

test('notification casts channel to enum', function () {
    $notification = new Notification(['channel' => 'sms']);

    expect($notification->channel)->toBe(Channel::SMS);
});

test('notification casts priority to enum', function () {
    $notification = new Notification(['priority' => 'high']);

    expect($notification->priority)->toBe(Priority::HIGH);
});

test('notification casts status to enum', function () {
    $notification = new Notification(['status' => 'pending']);

    expect($notification->status)->toBe(Status::PENDING);
});

test('notification factory creates a valid notification', function () {
    $notification = Notification::factory()->make();

    expect($notification->recipient)->not->toBeEmpty()
        ->and($notification->channel)->toBeInstanceOf(Channel::class)
        ->and($notification->priority)->toBeInstanceOf(Priority::class)
        ->and($notification->status)->toBe(Status::PENDING);
});

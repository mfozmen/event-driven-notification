<?php

use App\Enums\Status;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const BATCH_CORRELATION_ID = 'batch-correlation-id';
const BATCH_UNIT_RECIPIENT_1 = '+905551234567';
const BATCH_UNIT_RECIPIENT_2 = '+905551234568';

test('createBatch returns batch_id and count', function () {
    $service = new NotificationService;

    $result = $service->createBatch([
        [
            'recipient' => BATCH_UNIT_RECIPIENT_1,
            'channel' => 'sms',
            'content' => 'Hello 1',
        ],
        [
            'recipient' => BATCH_UNIT_RECIPIENT_2,
            'channel' => 'email',
            'content' => 'Hello 2',
        ],
    ], BATCH_CORRELATION_ID);

    expect($result->batchId)->toBeString();
    expect($result->count)->toBe(2);
});

test('createBatch assigns same batch_id to all notifications', function () {
    $service = new NotificationService;

    $result = $service->createBatch([
        [
            'recipient' => BATCH_UNIT_RECIPIENT_1,
            'channel' => 'sms',
            'content' => 'Hello 1',
        ],
        [
            'recipient' => BATCH_UNIT_RECIPIENT_2,
            'channel' => 'sms',
            'content' => 'Hello 2',
        ],
    ], BATCH_CORRELATION_ID);

    $notifications = Notification::where('batch_id', $result->batchId)->get();

    expect($notifications)->toHaveCount(2);
    expect($notifications->pluck('batch_id')->unique())->toHaveCount(1);
});

test('createBatch sets all notifications to pending status', function () {
    $service = new NotificationService;

    $result = $service->createBatch([
        [
            'recipient' => BATCH_UNIT_RECIPIENT_1,
            'channel' => 'sms',
            'content' => 'Hello',
        ],
    ], BATCH_CORRELATION_ID);

    $notification = Notification::where('batch_id', $result->batchId)->first();

    expect($notification->status)->toBe(Status::PENDING);
});

test('createBatch shares correlation_id across all notifications', function () {
    $service = new NotificationService;
    $correlationId = Str::orderedUuid()->toString();

    $result = $service->createBatch([
        [
            'recipient' => BATCH_UNIT_RECIPIENT_1,
            'channel' => 'sms',
            'content' => 'Hello 1',
        ],
        [
            'recipient' => BATCH_UNIT_RECIPIENT_2,
            'channel' => 'sms',
            'content' => 'Hello 2',
        ],
    ], $correlationId);

    $correlationIds = Notification::where('batch_id', $result->batchId)
        ->pluck('correlation_id')
        ->unique();

    expect($correlationIds)->toHaveCount(1);
    expect($correlationIds->first())->toBe($correlationId);
});

test('createBatch defaults priority to normal', function () {
    $service = new NotificationService;

    $result = $service->createBatch([
        [
            'recipient' => BATCH_UNIT_RECIPIENT_1,
            'channel' => 'sms',
            'content' => 'Hello',
        ],
    ], BATCH_CORRELATION_ID);

    $notification = Notification::where('batch_id', $result->batchId)->first();

    expect($notification->priority->value)->toBe('normal');
});

test('batchStatus returns total and per-status counts', function () {
    $service = new NotificationService;
    $batchId = Str::orderedUuid()->toString();

    Notification::factory()->count(3)->create(['batch_id' => $batchId, 'status' => Status::PENDING]);
    Notification::factory()->count(2)->create(['batch_id' => $batchId, 'status' => Status::DELIVERED]);

    $result = $service->batchStatus($batchId);

    expect($result->total)->toBe(5);
    expect($result->statusCounts['pending'])->toBe(3);
    expect($result->statusCounts['delivered'])->toBe(2);
});

test('batchStatus returns null for non-existent batch', function () {
    $service = new NotificationService;

    $result = $service->batchStatus('non-existent-batch-id');

    expect($result)->toBeNull();
});

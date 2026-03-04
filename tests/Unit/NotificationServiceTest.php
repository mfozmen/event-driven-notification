<?php

use App\DTOs\CreateNotificationResult;
use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new NotificationService;
});

// --- create() ---

test('create returns CreateNotificationResult DTO', function () {
    $result = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'correlation_id' => Str::orderedUuid()->toString(),
    ]);

    expect($result)->toBeInstanceOf(CreateNotificationResult::class);
    expect($result->notification)->toBeInstanceOf(Notification::class);
    expect($result->existed)->toBeFalse();
});

test('create sets status to pending', function () {
    $result = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'correlation_id' => Str::orderedUuid()->toString(),
    ]);

    expect($result->notification->status)->toBe(Status::PENDING);
});

test('create defaults priority to normal', function () {
    $result = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'correlation_id' => Str::orderedUuid()->toString(),
    ]);

    expect($result->notification->priority)->toBe(Priority::NORMAL);
});

test('create uses provided priority', function () {
    $result = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => 'high',
        'correlation_id' => Str::orderedUuid()->toString(),
    ]);

    expect($result->notification->priority)->toBe(Priority::HIGH);
});

test('create returns existing notification for duplicate idempotency key', function () {
    $correlationId = Str::orderedUuid()->toString();

    $first = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'idempotency_key' => 'key-123',
        'correlation_id' => $correlationId,
    ]);

    $second = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'idempotency_key' => 'key-123',
        'correlation_id' => $correlationId,
    ]);

    expect($first->existed)->toBeFalse();
    expect($second->existed)->toBeTrue();
    expect($first->notification->id)->toBe($second->notification->id);
});

test('create stores correlation_id from data', function () {
    $correlationId = Str::orderedUuid()->toString();

    $result = $this->service->create([
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'correlation_id' => $correlationId,
    ]);

    expect($result->notification->correlation_id)->toBe($correlationId);
});

// --- cancel() ---

test('cancel transitions cancellable notification to cancelled', function (Status $status) {
    $notification = Notification::factory()->create(['status' => $status]);

    $result = $this->service->cancel($notification);

    expect($result->status)->toBe(Status::CANCELLED);
})->with([
    Status::PENDING,
    Status::QUEUED,
    Status::RETRYING,
]);

test('cancel aborts 409 for non-cancellable notification', function (Status $status) {
    $notification = Notification::factory()->create(['status' => $status]);

    $this->service->cancel($notification);
})->with([
    Status::PROCESSING,
    Status::DELIVERED,
    Status::FAILED,
    Status::PERMANENTLY_FAILED,
])->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

// --- list() ---

test('list returns correct structure with defaults', function () {
    Notification::factory()->count(3)->create();

    $result = $this->service->list([]);

    expect($result)->toHaveKeys(['notifications', 'per_page', 'next_cursor']);
    expect($result['per_page'])->toBe(15);
    expect($result['notifications'])->toHaveCount(3);
    expect($result['next_cursor'])->toBeNull();
});

test('list filters by status', function () {
    Notification::factory()->create(['status' => Status::PENDING]);
    Notification::factory()->create(['status' => Status::DELIVERED]);

    $result = $this->service->list(['status' => 'pending']);

    expect($result['notifications'])->toHaveCount(1);
    expect($result['notifications']->first()->status)->toBe(Status::PENDING);
});

test('list filters by channel', function () {
    Notification::factory()->create(['channel' => Channel::SMS]);
    Notification::factory()->create(['channel' => Channel::EMAIL]);

    $result = $this->service->list(['channel' => 'sms']);

    expect($result['notifications'])->toHaveCount(1);
    expect($result['notifications']->first()->channel)->toBe(Channel::SMS);
});

test('list returns next_cursor when more results exist', function () {
    Notification::factory()->count(5)->create();

    $result = $this->service->list(['per_page' => 2]);

    expect($result['notifications'])->toHaveCount(2);
    expect($result['next_cursor'])->not->toBeNull();
});

test('list returns null next_cursor on last page', function () {
    Notification::factory()->count(2)->create();

    $result = $this->service->list(['per_page' => 5]);

    expect($result['notifications'])->toHaveCount(2);
    expect($result['next_cursor'])->toBeNull();
});

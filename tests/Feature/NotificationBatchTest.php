<?php

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const TEST_RECIPIENT_1 = '+905551234567';
const TEST_RECIPIENT_2 = '+905551234568';

test('storeBatch creates notifications and returns batch summary', function () {
    $payload = [
        'notifications' => [
            [
                'recipient' => TEST_RECIPIENT_1,
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => TEST_RECIPIENT_2,
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ];

    $response = $this->postJson('/api/notifications/batch', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'batch_id',
                'count',
            ],
        ])
        ->assertJsonPath('data.count', 2);

    $this->assertDatabaseCount('notifications', 2);
});

test('storeBatch returns 422 when exceeding 1000 notifications', function () {
    $notifications = array_fill(0, 1001, [
        'recipient' => TEST_RECIPIENT_1,
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => $notifications,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['notifications']);
});

test('storeBatch returns 422 when one item has validation error', function () {
    $payload = [
        'notifications' => [
            [
                'recipient' => TEST_RECIPIENT_1,
                'channel' => 'sms',
                'content' => 'Valid message',
            ],
            [
                'recipient' => TEST_RECIPIENT_2,
                'channel' => 'telegram',
                'content' => 'Invalid channel',
            ],
        ],
    ];

    $response = $this->postJson('/api/notifications/batch', $payload);

    $response->assertStatus(422);
    $this->assertDatabaseCount('notifications', 0);
});

test('storeBatch returns 422 when sms content exceeds 160 chars', function () {
    $payload = [
        'notifications' => [
            [
                'recipient' => TEST_RECIPIENT_1,
                'channel' => 'sms',
                'content' => str_repeat('a', 161),
            ],
        ],
    ];

    $response = $this->postJson('/api/notifications/batch', $payload);

    $response->assertStatus(422);
});

test('storeBatch returns 422 for empty notifications array', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['notifications']);
});

test('storeBatch assigns same batch_id to all notifications', function () {
    $payload = [
        'notifications' => [
            [
                'recipient' => TEST_RECIPIENT_1,
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => TEST_RECIPIENT_2,
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ];

    $response = $this->postJson('/api/notifications/batch', $payload);
    $batchId = $response->json('data.batch_id');

    $batchIds = Notification::pluck('batch_id')->unique();

    expect($batchIds)->toHaveCount(1);
    expect($batchIds->first())->toBe($batchId);
});

test('storeBatch assigns same correlation_id to all notifications', function () {
    $correlationId = 'shared-batch-correlation-id';

    $payload = [
        'notifications' => [
            [
                'recipient' => TEST_RECIPIENT_1,
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => TEST_RECIPIENT_2,
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ];

    $response = $this->postJson('/api/notifications/batch', $payload, [
        'X-Correlation-ID' => $correlationId,
    ]);

    $correlationIds = Notification::pluck('correlation_id')->unique();

    expect($correlationIds)->toHaveCount(1);
    expect($correlationIds->first())->toBe($correlationId);
});

test('storeBatch handles large batch with chunking', function () {
    $notifications = [];
    for ($i = 0; $i < 150; $i++) {
        $notifications[] = [
            'recipient' => TEST_RECIPIENT_1,
            'channel' => 'sms',
            'content' => "Message {$i}",
        ];
    }

    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => $notifications,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.count', 150);

    $this->assertDatabaseCount('notifications', 150);
});

test('batchStatus returns total and per-status counts', function () {
    $batchId = Str::orderedUuid()->toString();

    Notification::factory()->count(3)->create(['batch_id' => $batchId, 'status' => Status::PENDING]);
    Notification::factory()->count(2)->create(['batch_id' => $batchId, 'status' => Status::DELIVERED]);

    $response = $this->getJson("/api/notifications/batch/{$batchId}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'batch_id',
                'total',
                'status_counts',
            ],
        ])
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.status_counts.pending', 3)
        ->assertJsonPath('data.status_counts.delivered', 2);
});

test('batchStatus returns 404 for non-existent batch', function () {
    $response = $this->getJson('/api/notifications/batch/non-existent-id');

    $response->assertStatus(404);
});

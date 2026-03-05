<?php

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('storeBatch creates notifications and returns batch summary', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => '+905551234568',
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ]);

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
        'recipient' => '+905551234567',
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
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Valid message',
            ],
            [
                'recipient' => '+905551234568',
                'channel' => 'telegram',
                'content' => 'Invalid channel',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('notifications', 0);
});

test('storeBatch returns 422 when sms content exceeds 160 chars', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => str_repeat('a', 161),
            ],
        ],
    ]);

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
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => '+905551234568',
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ]);

    $batchId = $response->json('data.batch_id');
    $batchIds = Notification::pluck('batch_id')->unique();

    expect($batchIds)->toHaveCount(1);
    expect($batchIds->first())->toBe($batchId);
});

test('storeBatch assigns same correlation_id to all notifications', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Hello 1',
            ],
            [
                'recipient' => '+905551234568',
                'channel' => 'email',
                'content' => 'Hello 2',
            ],
        ],
    ], [
        'X-Correlation-ID' => 'shared-batch-correlation-id',
    ]);

    $correlationIds = Notification::pluck('correlation_id')->unique();

    expect($correlationIds)->toHaveCount(1);
    expect($correlationIds->first())->toBe('shared-batch-correlation-id');
});

test('storeBatch handles large batch with chunking', function () {
    $notifications = [];
    for ($i = 0; $i < 150; $i++) {
        $notifications[] = [
            'recipient' => '+905551234567',
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

test('storeBatch creates single notification', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Hello',
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.count', 1);
    $this->assertDatabaseCount('notifications', 1);
});

test('storeBatch returns 422 for mixed valid and invalid priorities', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Hello',
                'priority' => 'high',
            ],
            [
                'recipient' => '+905551234568',
                'channel' => 'sms',
                'content' => 'Hello',
                'priority' => 'urgent',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('notifications', 0);
});

test('storeBatch returns 422 for email content exceeding 10000 chars', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => 'user@example.com',
                'channel' => 'email',
                'content' => str_repeat('a', 10001),
            ],
        ],
    ]);

    $response->assertStatus(422);
});

test('storeBatch returns 422 for push content exceeding 500 chars', function () {
    $response = $this->postJson('/api/notifications/batch', [
        'notifications' => [
            [
                'recipient' => 'device-token-123',
                'channel' => 'push',
                'content' => str_repeat('a', 501),
            ],
        ],
    ]);

    $response->assertStatus(422);
});

<?php

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('store creates notification with valid data', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello, world!',
        'priority' => 'high',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'recipient',
                'channel',
                'content',
                'priority',
                'status',
                'correlation_id',
                'created_at',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.recipient', '+905551234567')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.content', 'Hello, world!')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseCount('notifications', 1);
});

test('store returns correlation id in response header', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    $response->assertStatus(201)
        ->assertHeader('X-Correlation-ID');
});

test('store uses correlation id from request header', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ], [
        'X-Correlation-ID' => 'my-custom-correlation-id',
    ]);

    $response->assertStatus(201)
        ->assertHeader('X-Correlation-ID', 'my-custom-correlation-id')
        ->assertJsonPath('data.correlation_id', 'my-custom-correlation-id');
});

test('store returns 422 when required fields are missing', function () {
    $response = $this->postJson('/api/notifications', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient', 'channel', 'content']);
});

test('store returns 422 for invalid channel', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'telegram',
        'content' => 'Hello',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['channel']);
});

test('store returns 422 for invalid priority', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => 'urgent',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['priority']);
});

test('store returns 422 when sms content exceeds 160 chars', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => str_repeat('a', 161),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('store returns existing notification when idempotency key is duplicate', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'idempotency_key' => 'unique-key-123',
    ];

    $first = $this->postJson('/api/notifications', $payload);
    $first->assertStatus(201);

    $second = $this->postJson('/api/notifications', $payload);
    $second->assertStatus(200);

    $this->assertDatabaseCount('notifications', 1);
    expect($first->json('data.id'))->toBe($second->json('data.id'));
});

test('store defaults priority to normal when not provided', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.priority', 'normal');
});

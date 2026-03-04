<?php

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('post notification returns 201 with correct structure', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello, world!',
        'priority' => 'high',
    ];

    $response = $this->postJson('/api/notifications', $payload);

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

test('post notification returns 422 for missing required fields', function () {
    $response = $this->postJson('/api/notifications', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient', 'channel', 'content']);
});

test('post notification returns 422 for invalid channel', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'telegram',
        'content' => 'Hello',
    ];

    $response = $this->postJson('/api/notifications', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['channel']);
});

test('post notification returns 422 for invalid priority', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => 'urgent',
    ];

    $response = $this->postJson('/api/notifications', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['priority']);
});

test('post sms notification returns 422 when content exceeds 160 chars', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => str_repeat('a', 161),
    ];

    $response = $this->postJson('/api/notifications', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('post notification with idempotency key prevents duplicate', function () {
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

test('post notification defaults priority to normal', function () {
    $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
    ];

    $response = $this->postJson('/api/notifications', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.priority', 'normal');
});

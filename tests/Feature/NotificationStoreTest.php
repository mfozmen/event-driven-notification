<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

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
        ->assertJsonPath('data.status', 'queued');

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

test('store ignores extra unknown fields in payload', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'extra_field' => 'should be ignored',
        'another_field' => 123,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.recipient', '+905551234567');
    $this->assertDatabaseCount('notifications', 1);
});

test('store returns 422 for empty string recipient', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '',
        'channel' => 'sms',
        'content' => 'Hello',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient']);
});

test('store returns 422 for empty string content', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('store returns 422 for email content exceeding 10000 chars', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'content' => str_repeat('a', 10001),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('store accepts email content within 10000 char limit', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'content' => str_repeat('a', 10000),
    ]);

    $response->assertStatus(201);
});

test('store returns 422 for push content exceeding 500 chars', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => 'device-token-123',
        'channel' => 'push',
        'content' => str_repeat('a', 501),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('store accepts push content within 500 char limit', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => 'device-token-123',
        'channel' => 'push',
        'content' => str_repeat('a', 500),
    ]);

    $response->assertStatus(201);
});

test('store accepts html content without sanitizing', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'content' => '<h1>Hello</h1><script>alert("xss")</script>',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', '<h1>Hello</h1><script>alert("xss")</script>');
});

test('store accepts unicode and emoji content', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Merhaba! 🎉🚀',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', 'Merhaba! 🎉🚀');
});

test('store accepts null for optional priority and idempotency_key', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Hello',
        'priority' => null,
        'idempotency_key' => null,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.priority', 'normal');
});

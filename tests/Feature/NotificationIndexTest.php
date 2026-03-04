<?php

use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('index returns paginated list with correct structure', function () {
    Notification::factory()->count(3)->create();

    $response = $this->getJson('/api/notifications');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
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
            ],
            'meta' => [
                'per_page',
                'next_cursor',
            ],
        ])
        ->assertJsonCount(3, 'data');
});

test('index filters by status', function () {
    Notification::factory()->create(['status' => Status::PENDING]);
    Notification::factory()->create(['status' => Status::DELIVERED]);

    $response = $this->getJson('/api/notifications?status=pending');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'pending');
});

test('index filters by channel', function () {
    Notification::factory()->create(['channel' => Channel::SMS]);
    Notification::factory()->create(['channel' => Channel::EMAIL]);

    $response = $this->getJson('/api/notifications?channel=sms');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.channel', 'sms');
});

test('index filters by date range', function () {
    Notification::factory()->create(['created_at' => '2026-01-01 00:00:00']);
    Notification::factory()->create(['created_at' => '2026-03-01 00:00:00']);
    Notification::factory()->create(['created_at' => '2026-06-01 00:00:00']);

    $response = $this->getJson('/api/notifications?date_from=2026-02-01&date_to=2026-04-01');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

test('index combines multiple filters', function () {
    Notification::factory()->create(['status' => Status::PENDING, 'channel' => Channel::SMS]);
    Notification::factory()->create(['status' => Status::PENDING, 'channel' => Channel::EMAIL]);
    Notification::factory()->create(['status' => Status::DELIVERED, 'channel' => Channel::SMS]);

    $response = $this->getJson('/api/notifications?status=pending&channel=sms');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.channel', 'sms');
});

test('index paginates with cursor', function () {
    $notifications = Notification::factory()->count(5)->create();

    $firstPage = $this->getJson('/api/notifications?per_page=2');
    $firstPage->assertStatus(200)->assertJsonCount(2, 'data');

    $nextCursor = $firstPage->json('meta.next_cursor');
    expect($nextCursor)->not->toBeNull();

    $secondPage = $this->getJson("/api/notifications?per_page=2&cursor={$nextCursor}");
    $secondPage->assertStatus(200)->assertJsonCount(2, 'data');

    $firstIds = collect($firstPage->json('data'))->pluck('id');
    $secondIds = collect($secondPage->json('data'))->pluck('id');
    expect($firstIds->intersect($secondIds))->toBeEmpty();
});

test('index returns 422 for invalid filter values', function () {
    $response = $this->getJson('/api/notifications?status=invalid');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

<?php

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('get notification returns 200 with correct structure', function () {
    $notification = Notification::factory()->create();

    $response = $this->getJson("/api/notifications/{$notification->id}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'id',
                'recipient',
                'channel',
                'content',
                'priority',
                'status',
                'correlation_id',
                'attempts',
                'max_attempts',
                'created_at',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.id', $notification->id);
});

test('get notification returns 404 for non-existent uuid', function () {
    $response = $this->getJson('/api/notifications/non-existent-uuid');

    $response->assertStatus(404);
});

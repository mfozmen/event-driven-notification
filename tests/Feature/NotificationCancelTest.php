<?php

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cancel changes pending notification to cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::PENDING]);

    $response = $this->patchJson("/api/notifications/{$notification->id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => 'cancelled',
    ]);
});

test('cancel changes queued notification to cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::QUEUED]);

    $response = $this->patchJson("/api/notifications/{$notification->id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');
});

test('cancel changes retrying notification to cancelled', function () {
    $notification = Notification::factory()->create(['status' => Status::RETRYING]);

    $response = $this->patchJson("/api/notifications/{$notification->id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');
});

test('cancel returns 409 for non-cancellable statuses', function (Status $status) {
    $notification = Notification::factory()->create(['status' => $status]);

    $response = $this->patchJson("/api/notifications/{$notification->id}/cancel");

    $response->assertStatus(409);
})->with([
    Status::PROCESSING,
    Status::DELIVERED,
    Status::FAILED,
    Status::PERMANENTLY_FAILED,
]);

test('cancel returns 404 for non-existent notification', function () {
    $response = $this->patchJson('/api/notifications/non-existent-uuid/cancel');

    $response->assertStatus(404);
});

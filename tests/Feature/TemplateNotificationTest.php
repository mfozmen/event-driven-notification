<?php

use App\Jobs\SendNotificationJob;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->template = NotificationTemplate::create([
        'name' => 'welcome-sms',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}, welcome to {{company}}!',
        'variables' => ['name', 'company'],
    ]);
});

test('store creates notification with template_id and renders content from template', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'template_id' => $this->template->id,
        'template_variables' => ['name' => 'John', 'company' => 'Acme'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', 'Hello John, welcome to Acme!');

    $this->assertDatabaseHas('notifications', [
        'content' => 'Hello John, welcome to Acme!',
        'template_id' => $this->template->id,
    ]);
});

test('store returns 422 when template_id provided but required variable is missing', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'template_id' => $this->template->id,
        'template_variables' => ['name' => 'John'],
    ]);

    $response->assertStatus(422);
});

test('store uses template content when both template_id and content are provided', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'This should be overwritten',
        'template_id' => $this->template->id,
        'template_variables' => ['name' => 'John', 'company' => 'Acme'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', 'Hello John, welcome to Acme!');
});

test('store returns 422 when neither content nor template_id is provided', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
    ]);

    $response->assertStatus(422);
});

test('store returns 422 when template_id does not exist', function () {
    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'template_id' => 'non-existent-uuid',
        'template_variables' => ['name' => 'John', 'company' => 'Acme'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['template_id']);
});

test('store creates notification with template that has no variables', function () {
    $staticTemplate = NotificationTemplate::create([
        'name' => 'static-sms',
        'channel' => 'sms',
        'body_template' => 'This is a static notification.',
        'variables' => [],
    ]);

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'template_id' => $staticTemplate->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', 'This is a static notification.');
});

test('store creates notification with template containing special characters', function () {
    $template = NotificationTemplate::create([
        'name' => 'promo-sms',
        'channel' => 'sms',
        'body_template' => 'Price: ${{amount}} — use "{{code}}" & save!',
        'variables' => ['amount', 'code'],
    ]);

    $response = $this->postJson('/api/notifications', [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'template_id' => $template->id,
        'template_variables' => ['amount' => '29.99', 'code' => 'SAVE20'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.content', 'Price: $29.99 — use "SAVE20" & save!');
});

<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('store creates a template with valid data', function () {
    $response = $this->postJson('/api/templates', [
        'name' => 'welcome-sms',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}, welcome to {{company}}!',
        'variables' => ['name', 'company'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'welcome-sms')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.body_template', 'Hello {{name}}, welcome to {{company}}!')
        ->assertJsonPath('data.variables', ['name', 'company']);

    $this->assertDatabaseHas('notification_templates', ['name' => 'welcome-sms']);
});

test('store returns 422 when creating template with duplicate name', function () {
    NotificationTemplate::create([
        'name' => 'welcome-sms',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}!',
        'variables' => ['name'],
    ]);

    $response = $this->postJson('/api/templates', [
        'name' => 'welcome-sms',
        'channel' => 'email',
        'body_template' => 'Different body',
        'variables' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('store returns 422 when required fields are missing', function () {
    $response = $this->postJson('/api/templates', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'channel', 'body_template']);
});

test('store returns 422 for invalid channel', function () {
    $response = $this->postJson('/api/templates', [
        'name' => 'test-template',
        'channel' => 'invalid',
        'body_template' => 'Hello!',
        'variables' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['channel']);
});

test('store creates template with empty variables array', function () {
    $response = $this->postJson('/api/templates', [
        'name' => 'static-template',
        'channel' => 'email',
        'body_template' => 'Static content with no variables.',
        'variables' => [],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.variables', []);
});

test('show returns a single template', function () {
    $template = NotificationTemplate::create([
        'name' => 'welcome-sms',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}!',
        'variables' => ['name'],
    ]);

    $response = $this->getJson("/api/templates/{$template->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $template->id)
        ->assertJsonPath('data.name', 'welcome-sms');
});

test('show returns 404 for non-existent template', function () {
    $response = $this->getJson('/api/templates/non-existent-uuid');

    $response->assertNotFound();
});

test('index lists all templates', function () {
    NotificationTemplate::create([
        'name' => 'template-1',
        'channel' => 'sms',
        'body_template' => 'Hello!',
        'variables' => [],
    ]);
    NotificationTemplate::create([
        'name' => 'template-2',
        'channel' => 'email',
        'body_template' => 'Hi {{name}}!',
        'variables' => ['name'],
    ]);

    $response = $this->getJson('/api/templates');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('index returns empty array when no templates exist', function () {
    $response = $this->getJson('/api/templates');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

test('update updates a template', function () {
    $template = NotificationTemplate::create([
        'name' => 'old-name',
        'channel' => 'sms',
        'body_template' => 'Old body {{name}}',
        'variables' => ['name'],
    ]);

    $response = $this->putJson("/api/templates/{$template->id}", [
        'name' => 'new-name',
        'channel' => 'email',
        'body_template' => 'New body {{user}}!',
        'variables' => ['user'],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'new-name')
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonPath('data.body_template', 'New body {{user}}!');
});

test('update returns 422 when updating name to existing name', function () {
    NotificationTemplate::create([
        'name' => 'existing-name',
        'channel' => 'sms',
        'body_template' => 'Body',
        'variables' => [],
    ]);

    $template = NotificationTemplate::create([
        'name' => 'other-name',
        'channel' => 'email',
        'body_template' => 'Body',
        'variables' => [],
    ]);

    $response = $this->putJson("/api/templates/{$template->id}", [
        'name' => 'existing-name',
        'channel' => 'email',
        'body_template' => 'Body',
        'variables' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('update allows keeping same name while changing other fields', function () {
    $template = NotificationTemplate::create([
        'name' => 'keep-name',
        'channel' => 'sms',
        'body_template' => 'Old body',
        'variables' => [],
    ]);

    $response = $this->putJson("/api/templates/{$template->id}", [
        'name' => 'keep-name',
        'channel' => 'email',
        'body_template' => 'New body',
        'variables' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'keep-name')
        ->assertJsonPath('data.channel', 'email');
});

test('update returns 404 for non-existent template', function () {
    $response = $this->putJson('/api/templates/non-existent-uuid', [
        'name' => 'test',
        'channel' => 'sms',
        'body_template' => 'Body',
        'variables' => [],
    ]);

    $response->assertNotFound();
});

test('destroy deletes a template not referenced by notifications', function () {
    $template = NotificationTemplate::create([
        'name' => 'to-delete',
        'channel' => 'sms',
        'body_template' => 'Body',
        'variables' => [],
    ]);

    $response = $this->deleteJson("/api/templates/{$template->id}");

    $response->assertStatus(204);
    $this->assertDatabaseMissing('notification_templates', ['id' => $template->id]);
});

test('destroy returns 409 when template is referenced by notifications', function () {
    $template = NotificationTemplate::create([
        'name' => 'referenced-template',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}!',
        'variables' => ['name'],
    ]);

    Notification::factory()->create(['template_id' => $template->id]);

    $response = $this->deleteJson("/api/templates/{$template->id}");

    $response->assertStatus(409);
    $this->assertDatabaseHas('notification_templates', ['id' => $template->id]);
});

test('destroy returns 404 for non-existent template', function () {
    $response = $this->deleteJson('/api/templates/non-existent-uuid');

    $response->assertNotFound();
});

<?php

use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->template = NotificationTemplate::create([
        'name' => 'welcome-sms',
        'channel' => 'sms',
        'body_template' => 'Hello {{name}}, welcome to {{company}}!',
        'variables' => ['name', 'company'],
    ]);
});

test('render substitutes variables correctly', function () {
    $result = $this->template->render(['name' => 'John', 'company' => 'Acme']);

    expect($result)->toBe('Hello John, welcome to Acme!');
});

test('render throws exception when required variable is missing', function () {
    $this->template->render(['name' => 'John']);
})->throws(InvalidArgumentException::class, 'Missing required template variable: company');

test('render handles extra variables gracefully', function () {
    $result = $this->template->render([
        'name' => 'John',
        'company' => 'Acme',
        'extra' => 'ignored',
    ]);

    expect($result)->toBe('Hello John, welcome to Acme!');
});

test('render works with no variables for static content', function () {
    $template = NotificationTemplate::create([
        'name' => 'static-template',
        'channel' => 'email',
        'body_template' => 'This is a static message with no variables.',
        'variables' => [],
    ]);

    $result = $template->render([]);

    expect($result)->toBe('This is a static message with no variables.');
});

test('render handles special characters in body template', function () {
    $template = NotificationTemplate::create([
        'name' => 'special-chars',
        'channel' => 'push',
        'body_template' => 'Price: ${{amount}} — use code "{{code}}" & save!',
        'variables' => ['amount', 'code'],
    ]);

    $result = $template->render(['amount' => '29.99', 'code' => 'SAVE20']);

    expect($result)->toBe('Price: $29.99 — use code "SAVE20" & save!');
});

test('render substitutes multiple occurrences of the same variable', function () {
    $template = NotificationTemplate::create([
        'name' => 'repeated-var',
        'channel' => 'sms',
        'body_template' => '{{name}} said hello. Hi {{name}}!',
        'variables' => ['name'],
    ]);

    $result = $template->render(['name' => 'Alice']);

    expect($result)->toBe('Alice said hello. Hi Alice!');
});

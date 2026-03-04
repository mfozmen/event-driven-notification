<?php

use App\Channels\SmsProvider;
use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provider = new SmsProvider;
    $this->notification = Notification::factory()->create([
        'channel' => Channel::SMS,
        'recipient' => '+905551234567',
        'content' => 'Test SMS message',
        'status' => Status::PROCESSING,
    ]);
});

test('send formats correct payload for SMS channel', function () {
    Http::fake([
        '*' => Http::response([
            'messageId' => 'msg-123',
            'status' => 'accepted',
            'timestamp' => now()->toIso8601String(),
        ], 202),
    ]);

    $this->provider->send($this->notification);

    Http::assertSent(function ($request) {
        $uuid = config('notifications.webhook.uuid');

        return $request->url() === "https://webhook.site/{$uuid}"
            && $request['to'] === '+905551234567'
            && $request['channel'] === 'sms'
            && $request['content'] === 'Test SMS message';
    });
});

test('send returns successful DeliveryResult on 202 response', function () {
    Http::fake([
        '*' => Http::response([
            'messageId' => 'msg-456',
            'status' => 'accepted',
            'timestamp' => now()->toIso8601String(),
        ], 202),
    ]);

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeTrue();
    expect($result->messageId)->toBe('msg-456');
    expect($result->errorMessage)->toBeNull();
});

test('send returns retryable failure on 500 response', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Internal Server Error'], 500),
    ]);

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeFalse();
    expect($result->isRetryable)->toBeTrue();
    expect($result->errorMessage)->not->toBeNull();
});

test('send returns retryable failure on timeout', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeFalse();
    expect($result->isRetryable)->toBeTrue();
    expect($result->errorMessage)->toContain('Connection timed out');
});

test('send returns non-retryable failure on 400 response', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Bad Request'], 400),
    ]);

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeFalse();
    expect($result->isRetryable)->toBeFalse();
    expect($result->errorMessage)->not->toBeNull();
});

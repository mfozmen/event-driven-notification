<?php

use App\Channels\PushProvider;
use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provider = new PushProvider;
    $this->notification = Notification::factory()->create([
        'channel' => Channel::PUSH,
        'recipient' => 'device-token-abc',
        'content' => 'Test push notification',
        'status' => Status::PROCESSING,
    ]);
});

test('send formats correct payload for PUSH channel', function () {
    Http::fake([
        '*' => Http::response([
            'messageId' => 'msg-123',
            'status' => 'accepted',
            'timestamp' => now()->toIso8601String(),
        ], 202),
    ]);

    $this->provider->send($this->notification);

    Http::assertSent(function ($request) {
        return $request['to'] === 'device-token-abc'
            && $request['channel'] === 'push'
            && $request['content'] === 'Test push notification';
    });
});

test('send returns successful DeliveryResult on 202 response', function () {
    Http::fake([
        '*' => Http::response([
            'messageId' => 'msg-push-1',
            'status' => 'accepted',
            'timestamp' => now()->toIso8601String(),
        ], 202),
    ]);

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeTrue();
    expect($result->messageId)->toBe('msg-push-1');
});

test('send returns retryable failure on 500 response', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Internal Server Error'], 500),
    ]);

    $result = $this->provider->send($this->notification);

    expect($result->success)->toBeFalse();
    expect($result->isRetryable)->toBeTrue();
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
});

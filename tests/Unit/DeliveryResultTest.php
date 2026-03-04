<?php

use App\DTOs\DeliveryResult;

test('successful factory creates a successful result with messageId', function () {
    $result = DeliveryResult::successful('msg-123');

    expect($result->success)->toBeTrue();
    expect($result->messageId)->toBe('msg-123');
    expect($result->errorMessage)->toBeNull();
    expect($result->isRetryable)->toBeFalse();
});

test('failure factory creates a failed result with error and retryable flag', function () {
    $result = DeliveryResult::failure('Server error', true);

    expect($result->success)->toBeFalse();
    expect($result->messageId)->toBeNull();
    expect($result->errorMessage)->toBe('Server error');
    expect($result->isRetryable)->toBeTrue();
});

test('failure factory defaults isRetryable to true', function () {
    $result = DeliveryResult::failure('Timeout');

    expect($result->isRetryable)->toBeTrue();
});

test('failure factory accepts non-retryable errors', function () {
    $result = DeliveryResult::failure('Bad request', false);

    expect($result->isRetryable)->toBeFalse();
});

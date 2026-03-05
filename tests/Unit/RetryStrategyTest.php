<?php

use App\DTOs\DeliveryResult;
use App\Services\RetryStrategy;

beforeEach(function () {
    $this->strategy = new RetryStrategy;
});

test('calculateDelay returns exponential backoff with base delay', function () {
    config(['notifications.retry.base_delay_seconds' => 30]);

    $delay1 = $this->strategy->calculateDelay(1);
    $delay2 = $this->strategy->calculateDelay(2);
    $delay3 = $this->strategy->calculateDelay(3);

    // base * 2^(attempt-1) + random(0, base): 30-60, 60-90, 120-150
    expect($delay1)->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(60);
    expect($delay2)->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(90);
    expect($delay3)->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(150);
});

test('calculateDelay adds jitter up to 1000ms', function () {
    config(['notifications.retry.base_delay_seconds' => 30]);

    $delays = [];
    for ($i = 0; $i < 50; $i++) {
        $delays[] = $this->strategy->calculateDelay(1);
    }

    // All delays should be between 30 and 60 (30 + 0-30s jitter)
    foreach ($delays as $delay) {
        expect($delay)->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(60);
    }

    // Jitter should produce varying values, not all identical
    expect(collect($delays)->unique()->count())->toBeGreaterThan(1);
});

test('shouldRetry returns true when retryable and attempts remaining', function () {
    $result = DeliveryResult::failure('Server error', true);

    expect($this->strategy->shouldRetry($result, 1, 3))->toBeTrue();
    expect($this->strategy->shouldRetry($result, 2, 3))->toBeTrue();
});

test('shouldRetry returns false when not retryable or max attempts reached', function () {
    $nonRetryable = DeliveryResult::failure('Bad request', false);
    $retryable = DeliveryResult::failure('Server error', true);

    // Non-retryable: always false regardless of attempts
    expect($this->strategy->shouldRetry($nonRetryable, 1, 3))->toBeFalse();
    expect($this->strategy->shouldRetry($nonRetryable, 0, 3))->toBeFalse();

    // Retryable but max attempts reached
    expect($this->strategy->shouldRetry($retryable, 3, 3))->toBeFalse();
    expect($this->strategy->shouldRetry($retryable, 4, 3))->toBeFalse();
});

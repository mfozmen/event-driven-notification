<?php

use App\Enums\Channel;
use App\Services\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->breaker = new CircuitBreaker;
    $this->store = [];

    Redis::shouldReceive('get')
        ->andReturnUsing(function (string $key) {
            return $this->store[$key] ?? null;
        });

    Redis::shouldReceive('set')
        ->andReturnUsing(function (string $key, $value) {
            $this->store[$key] = (string) $value;

            return true;
        });

    Redis::shouldReceive('del')
        ->andReturnUsing(function (...$keys) {
            foreach ($keys as $key) {
                unset($this->store[$key]);
            }

            return count($keys);
        });

    Redis::shouldReceive('incr')
        ->andReturnUsing(function (string $key) {
            $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + 1;

            return $this->store[$key];
        });

    Redis::shouldReceive('expire')
        ->andReturn(true);
});

test('isAvailable returns true when circuit is closed', function () {
    expect($this->breaker->isAvailable(Channel::SMS))->toBeTrue();
});

test('isAvailable returns false when failure threshold exceeded', function () {
    config(['notifications.circuit_breaker.failure_threshold' => 5]);
    config(['notifications.circuit_breaker.window_seconds' => 60]);

    for ($i = 0; $i < 5; $i++) {
        $this->breaker->recordFailure(Channel::SMS);
    }

    expect($this->breaker->isAvailable(Channel::SMS))->toBeFalse();
});

test('isAvailable returns true when cooldown has elapsed (half-open)', function () {
    config(['notifications.circuit_breaker.cooldown_seconds' => 30]);

    // Simulate circuit opened 31 seconds ago
    $this->store['circuit_breaker:sms:state'] = 'open';
    $this->store['circuit_breaker:sms:opened_at'] = (string) (time() - 31);

    expect($this->breaker->isAvailable(Channel::SMS))->toBeTrue();
});

test('recordSuccess resets circuit to closed', function () {
    // Set up open circuit state
    $this->store['circuit_breaker:sms:state'] = 'open';
    $this->store['circuit_breaker:sms:opened_at'] = (string) time();
    $this->store['circuit_breaker:sms:failures'] = '5';

    $this->breaker->recordSuccess(Channel::SMS);

    // All keys should be cleared
    expect($this->store)->not->toHaveKey('circuit_breaker:sms:state');
    expect($this->store)->not->toHaveKey('circuit_breaker:sms:opened_at');
    expect($this->store)->not->toHaveKey('circuit_breaker:sms:failures');

    // Circuit should be closed (available)
    expect($this->breaker->isAvailable(Channel::SMS))->toBeTrue();
});

test('circuits track channels independently', function () {
    config(['notifications.circuit_breaker.failure_threshold' => 5]);
    config(['notifications.circuit_breaker.window_seconds' => 60]);

    for ($i = 0; $i < 5; $i++) {
        $this->breaker->recordFailure(Channel::SMS);
    }

    expect($this->breaker->isAvailable(Channel::SMS))->toBeFalse();
    expect($this->breaker->isAvailable(Channel::EMAIL))->toBeTrue();
});

test('circuit stays closed when failures are below threshold', function () {
    config(['notifications.circuit_breaker.failure_threshold' => 5]);
    config(['notifications.circuit_breaker.window_seconds' => 60]);

    for ($i = 0; $i < 4; $i++) {
        $this->breaker->recordFailure(Channel::SMS);
    }

    expect($this->breaker->isAvailable(Channel::SMS))->toBeTrue();
});

test('recordFailure in half-open state reopens circuit', function () {
    config(['notifications.circuit_breaker.failure_threshold' => 5]);
    config(['notifications.circuit_breaker.window_seconds' => 60]);
    config(['notifications.circuit_breaker.cooldown_seconds' => 30]);

    // Simulate half-open: circuit was open, cooldown elapsed
    $this->store['circuit_breaker:sms:state'] = 'open';
    $this->store['circuit_breaker:sms:opened_at'] = (string) (time() - 31);

    // Verify half-open allows request
    expect($this->breaker->isAvailable(Channel::SMS))->toBeTrue();

    // Record failure during half-open — should reopen with fresh timestamp
    $this->breaker->recordFailure(Channel::SMS);

    expect($this->store['circuit_breaker:sms:state'])->toBe('open');
    expect((int) $this->store['circuit_breaker:sms:opened_at'])->toBeGreaterThanOrEqual(time() - 1);
});

<?php

use App\Enums\Channel;
use App\Services\ChannelRateLimiter;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->limiter = new ChannelRateLimiter;
    $this->counters = [];

    Redis::shouldReceive('incr')
        ->andReturnUsing(function (string $key) {
            $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;

            return $this->counters[$key];
        });

    Redis::shouldReceive('expire')
        ->withArgs(fn ($key, $ttl) => $ttl === 2)
        ->andReturn(true);
});

test('attempt allows requests under limit', function () {
    $result = $this->limiter->attempt(Channel::SMS);

    expect($result)->toBeTrue();
});

test('attempt blocks when limit exceeded', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->limiter->attempt(Channel::SMS);
    }

    $result = $this->limiter->attempt(Channel::SMS);

    expect($result)->toBeFalse();
});

test('attempt tracks channels independently', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->limiter->attempt(Channel::SMS);
    }

    $result = $this->limiter->attempt(Channel::EMAIL);

    expect($result)->toBeTrue();
});

test('counter resets after window expires', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->limiter->attempt(Channel::SMS);
    }

    expect($this->limiter->attempt(Channel::SMS))->toBeFalse();

    // Simulate window expiry by clearing the counter for the current key
    $key = 'rate_limit:sms:'.time();
    $this->counters[$key] = 0;

    expect($this->limiter->attempt(Channel::SMS))->toBeTrue();
});

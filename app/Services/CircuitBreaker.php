<?php

namespace App\Services;

use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

class CircuitBreaker
{
    public function isAvailable(Channel $channel): bool
    {
        $state = Redis::get("circuit_breaker:{$channel->value}:state");

        if ($state === 'open') {
            $openedAt = (int) Redis::get("circuit_breaker:{$channel->value}:opened_at");

            /** @var int $cooldown */
            $cooldown = config('notifications.circuit_breaker.cooldown_seconds');

            if (time() - $openedAt >= $cooldown) {
                return true; // half-open: allow one request
            }

            return false; // still in cooldown
        }

        return true; // closed: allow
    }

    public function recordSuccess(Channel $channel): void
    {
        Redis::del(
            "circuit_breaker:{$channel->value}:state",
            "circuit_breaker:{$channel->value}:opened_at",
            "circuit_breaker:{$channel->value}:failures",
        );
    }

    public function recordFailure(Channel $channel): void
    {
        $state = Redis::get("circuit_breaker:{$channel->value}:state");

        if ($state === 'open') {
            // Half-open probe failed — reopen with fresh timestamp
            Redis::set("circuit_breaker:{$channel->value}:opened_at", time());

            return;
        }

        $key = "circuit_breaker:{$channel->value}:failures";

        /** @var int $window */
        $window = config('notifications.circuit_breaker.window_seconds');

        /** @var int $threshold */
        $threshold = config('notifications.circuit_breaker.failure_threshold');

        $count = (int) Redis::incr($key);

        if ($count === 1) {
            Redis::expire($key, $window);
        }

        if ($count >= $threshold) {
            Redis::set("circuit_breaker:{$channel->value}:state", 'open');
            Redis::set("circuit_breaker:{$channel->value}:opened_at", time());
        }
    }
}

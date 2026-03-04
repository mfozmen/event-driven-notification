<?php

namespace App\Services;

use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

class ChannelRateLimiter
{
    private const TTL_SECONDS = 2;

    public function attempt(Channel $channel): bool
    {
        $key = $this->buildKey($channel);
        $limit = (int) config('notifications.rate_limit.per_second', 100);

        $current = (int) Redis::incr($key);

        if ($current === 1) {
            Redis::expire($key, self::TTL_SECONDS);
        }

        return $current <= $limit;
    }

    private function buildKey(Channel $channel): string
    {
        return 'rate_limit:' . $channel->value . ':' . time();
    }
}

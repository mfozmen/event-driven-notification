<?php

namespace App\Services;

use App\DTOs\DeliveryResult;

class RetryStrategy
{
    public function shouldRetry(DeliveryResult $result, int $attempts, int $maxAttempts): bool
    {
        return $result->isRetryable && $attempts < $maxAttempts;
    }

    public function calculateDelay(int $attempt): int
    {
        /** @var int $baseDelay */
        $baseDelay = config('notifications.retry.base_delay_seconds');
        $exponentialDelay = $baseDelay * (2 ** ($attempt - 1));
        $jitter = random_int(0, $baseDelay);

        return $exponentialDelay + $jitter;
    }
}

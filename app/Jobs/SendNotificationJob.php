<?php

namespace App\Jobs;

use App\Channels\ChannelProviderFactory;
use App\DTOs\DeliveryResult;
use App\Enums\Status;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use App\Services\CircuitBreaker;
use App\Services\RetryStrategy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $notificationId,
    ) {}

    public function handle(ChannelRateLimiter $rateLimiter, ChannelProviderFactory $factory, RetryStrategy $retryStrategy, CircuitBreaker $circuitBreaker): void
    {
        $notification = Notification::find($this->notificationId);

        if (! $notification) {
            return;
        }

        if (! in_array($notification->status, [Status::QUEUED, Status::RETRYING])) {
            return;
        }

        if (! $rateLimiter->attempt($notification->channel)) {
            $this->release(1);

            return;
        }

        if (! $circuitBreaker->isAvailable($notification->channel)) {
            $this->release(30);

            return;
        }

        if (! $this->claimNotification($notification)) {
            return;
        }

        try {
            $provider = $factory->resolve($notification->channel);
            $result = $provider->send($notification);

            if ($result->success) {
                $notification->update([
                    'status' => Status::DELIVERED,
                    'delivered_at' => now(),
                ]);
                $circuitBreaker->recordSuccess($notification->channel);
            } else {
                $circuitBreaker->recordFailure($notification->channel);
                $this->handleFailure($notification, $result, $retryStrategy);
            }
        } catch (\Throwable $e) {
            $circuitBreaker->recordFailure($notification->channel);
            $result = DeliveryResult::failure($e->getMessage(), true);
            $this->handleFailure($notification, $result, $retryStrategy);
        }
    }

    private function handleFailure(Notification $notification, DeliveryResult $result, RetryStrategy $retryStrategy): void
    {
        if ($retryStrategy->shouldRetry($result, $notification->attempts, $notification->max_attempts)) {
            $delay = $retryStrategy->calculateDelay($notification->attempts);
            $notification->update([
                'status' => Status::RETRYING,
                'next_retry_at' => now()->addSeconds($delay),
                'error_message' => $result->errorMessage,
            ]);
            $this->release($delay);
        } else {
            $notification->update([
                'status' => Status::PERMANENTLY_FAILED,
                'failed_at' => now(),
                'error_message' => $result->errorMessage,
            ]);
        }
    }

    private function claimNotification(Notification $notification): bool
    {
        $affectedRows = Notification::where('id', $notification->id)
            ->whereIn('status', [Status::QUEUED, Status::RETRYING])
            ->update([
                'status' => Status::PROCESSING,
                'attempts' => $notification->attempts + 1,
                'last_attempted_at' => now(),
            ]);

        if ($affectedRows === 0) {
            return false;
        }

        $notification->refresh();

        return true;
    }
}

<?php

namespace App\Jobs;

use App\Channels\ChannelProviderFactory;
use App\DTOs\DeliveryResult;
use App\Enums\Status;
use App\Events\NotificationStatusUpdated;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use App\Services\CircuitBreaker;
use App\Services\NotificationLogger;
use App\Services\RetryStrategy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $notificationId,
    ) {}

    public function handle(ChannelRateLimiter $rateLimiter, ChannelProviderFactory $factory, RetryStrategy $retryStrategy, CircuitBreaker $circuitBreaker): void // NOSONAR — Guard clauses with early returns are intentional
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

        $logger = app(NotificationLogger::class);
        $this->safeLog($logger, $notification, 'processing');

        $start = microtime(true);

        try {
            $provider = $factory->resolve($notification->channel);
            $result = $provider->send($notification);

            $this->safeRecordMetrics($notification, $result->success, $start);

            if ($result->success) {
                $notification->update([
                    'status' => Status::DELIVERED,
                    'delivered_at' => now(),
                ]);
                $this->safeLog($logger, $notification, 'delivered');
                $this->safeBroadcast($notification);
                $circuitBreaker->recordSuccess($notification->channel);
            } else {
                $circuitBreaker->recordFailure($notification->channel);
                $this->handleFailure($notification, $result, $retryStrategy, $logger);
            }
        } catch (\Throwable $e) {
            $this->safeRecordMetrics($notification, false, $start);
            $circuitBreaker->recordFailure($notification->channel);
            $result = DeliveryResult::failure($e->getMessage(), true);
            $this->handleFailure($notification, $result, $retryStrategy, $logger);
        }
    }

    private function handleFailure(Notification $notification, DeliveryResult $result, RetryStrategy $retryStrategy, NotificationLogger $logger): void
    {
        if ($retryStrategy->shouldRetry($result, $notification->attempts, $notification->max_attempts)) {
            $delay = $retryStrategy->calculateDelay($notification->attempts);
            $notification->update([
                'status' => Status::RETRYING,
                'next_retry_at' => now()->addSeconds($delay),
                'error_message' => $result->errorMessage,
            ]);
            $this->safeLog($logger, $notification, 'retrying', ['error' => $result->errorMessage, 'delay' => $delay]);
            $this->safeBroadcast($notification);
            $this->release($delay);
        } else {
            $notification->update([
                'status' => Status::PERMANENTLY_FAILED,
                'failed_at' => now(),
                'error_message' => $result->errorMessage,
            ]);
            $this->safeLog($logger, $notification, 'permanently_failed', ['error' => $result->errorMessage]);
            $this->safeBroadcast($notification);
        }
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function safeLog(NotificationLogger $logger, Notification $notification, string $event, ?array $details = null): void
    {
        try {
            $logger->log($notification, $event, $details);
        } catch (\Throwable $e) {
            Log::error("Failed to log notification event: {$event}", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeBroadcast(Notification $notification): void
    {
        try {
            NotificationStatusUpdated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast notification status update', [
                'notification_id' => $notification->id,
                'status' => $notification->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeRecordMetrics(Notification $notification, bool $success, float $start): void
    {
        try {
            $channel = $notification->channel->value;
            $key = $success ? "metrics:deliveries:success:{$channel}" : "metrics:deliveries:failure:{$channel}";
            $latency = round((microtime(true) - $start) * 1000, 2);

            Redis::incr($key);
            Redis::expire($key, 3600);
            Redis::lpush("metrics:latency:{$channel}", $latency);
            Redis::ltrim("metrics:latency:{$channel}", 0, 99);
        } catch (\Throwable $e) {
            Log::error('Failed to record delivery metrics', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
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

<?php

namespace App\Jobs;

use App\Channels\ChannelProviderFactory;
use App\Enums\Status;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $notificationId,
    ) {}

    public function handle(ChannelRateLimiter $rateLimiter, ChannelProviderFactory $factory): void
    {
        $notification = Notification::find($this->notificationId);

        if (! $notification) {
            return;
        }

        if ($notification->status !== Status::QUEUED) {
            return;
        }

        if (! $rateLimiter->attempt($notification->channel)) {
            $this->release(1);

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
            } else {
                $notification->update([
                    'status' => Status::FAILED,
                    'failed_at' => now(),
                    'error_message' => $result->errorMessage,
                ]);
            }
        } catch (\Throwable $e) {
            $notification->update([
                'status' => Status::FAILED,
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function claimNotification(Notification $notification): bool
    {
        $affectedRows = Notification::where('id', $notification->id)
            ->where('status', Status::QUEUED)
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

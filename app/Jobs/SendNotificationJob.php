<?php

namespace App\Jobs;

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

    public function handle(ChannelRateLimiter $rateLimiter): void
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

        $this->claimNotification($notification);
    }

    private function claimNotification(Notification $notification): void
    {
        Notification::where('id', $notification->id)
            ->where('status', Status::QUEUED)
            ->update([
                'status' => Status::PROCESSING,
                'attempts' => $notification->attempts + 1,
                'last_attempted_at' => now(),
            ]);

        $notification->refresh();
    }
}

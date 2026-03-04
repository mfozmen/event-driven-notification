<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $notificationId,
    ) {}

    public function handle(): void
    {
        $notification = Notification::find($this->notificationId);

        if (! $notification) {
            return;
        }

        if (! $this->claimNotification($notification)) {
            return;
        }
    }

    private function claimNotification(Notification $notification): bool
    {
        $affected = Notification::where('id', $notification->id)
            ->where('status', Status::QUEUED)
            ->update([
                'status' => Status::PROCESSING,
                'attempts' => $notification->attempts + 1,
                'last_attempted_at' => now(),
            ]);

        if ($affected === 0) {
            return false;
        }

        $notification->refresh();

        return true;
    }
}

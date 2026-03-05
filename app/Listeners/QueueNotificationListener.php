<?php

namespace App\Listeners;

use App\Events\NotificationCreated;
use App\Jobs\SendNotificationJob;
use App\Services\NotificationLogger;
use Illuminate\Support\Facades\Log;

class QueueNotificationListener
{
    public function __construct(
        private NotificationLogger $logger,
    ) {}

    public function handle(NotificationCreated $event): void
    {
        try {
            $notification = $event->notification;

            if ($notification->scheduled_at && $notification->scheduled_at->isFuture()) {
                return;
            }

            $notification->update(['status' => 'queued']);

            $this->logger->log($notification, 'queued');

            SendNotificationJob::dispatch($notification->id)
                ->onQueue($notification->priority->value);
        } catch (\Throwable $e) {
            Log::error('QueueNotificationListener failed', [
                'notification_id' => $event->notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

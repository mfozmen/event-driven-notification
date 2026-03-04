<?php

namespace App\Listeners;

use App\Events\NotificationCreated;
use App\Jobs\SendNotificationJob;

class QueueNotificationListener
{
    public function handle(NotificationCreated $event): void
    {
        $notification = $event->notification;

        $notification->update(['status' => 'queued']);

        SendNotificationJob::dispatch($notification->id)
            ->onQueue($notification->priority->value);
    }
}

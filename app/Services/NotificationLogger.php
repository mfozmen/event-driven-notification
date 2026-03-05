<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationLog;

class NotificationLogger
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function log(Notification $notification, string $event, ?array $details = null): void
    {
        NotificationLog::create([
            'notification_id' => $notification->id,
            'correlation_id' => $notification->correlation_id,
            'event' => $event,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public Notification $notification,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("notifications.{$this->notification->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'status' => $this->notification->status->value,
            'attempts' => $this->notification->attempts,
            'updated_at' => $this->notification->updated_at->toIso8601String(),
        ];
    }
}

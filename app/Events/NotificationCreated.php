<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationCreated
{
    use Dispatchable;

    public function __construct(
        public Notification $notification,
    ) {}
}

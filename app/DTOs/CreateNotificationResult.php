<?php

namespace App\DTOs;

use App\Models\Notification;

readonly class CreateNotificationResult
{
    public function __construct(
        public Notification $notification,
        public bool $existed,
    ) {}
}

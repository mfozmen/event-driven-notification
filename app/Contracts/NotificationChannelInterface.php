<?php

namespace App\Contracts;

use App\DTOs\DeliveryResult;
use App\Models\Notification;

interface NotificationChannelInterface
{
    public function send(Notification $notification): DeliveryResult;
}

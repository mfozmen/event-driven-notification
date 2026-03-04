<?php

namespace App\Channels;

use App\Models\Notification;

class SmsProvider extends AbstractChannelProvider
{
    /** @return array<string, string> */
    protected function formatPayload(Notification $notification): array
    {
        return [
            'to' => $notification->recipient,
            'channel' => 'sms',
            'content' => $notification->content,
        ];
    }
}

<?php

namespace App\Channels;

use App\Models\Notification;

class PushProvider extends AbstractChannelProvider
{
    /** @return array<string, string> */
    protected function formatPayload(Notification $notification): array
    {
        return [
            'to' => $notification->recipient,
            'channel' => 'push',
            'content' => $notification->content,
        ];
    }
}

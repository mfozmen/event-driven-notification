<?php

namespace App\Channels;

use App\Models\Notification;

class EmailProvider extends AbstractChannelProvider
{
    /** @return array<string, string> */
    protected function formatPayload(Notification $notification): array
    {
        return [
            'to' => $notification->recipient,
            'channel' => 'email',
            'content' => $notification->content,
        ];
    }
}

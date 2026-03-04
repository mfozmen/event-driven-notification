<?php

namespace App\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Enums\Channel;

class ChannelProviderFactory
{
    public function resolve(Channel $channel): NotificationChannelInterface
    {
        return match ($channel) {
            Channel::SMS => new SmsProvider,
            Channel::EMAIL => new EmailProvider,
            Channel::PUSH => new PushProvider,
        };
    }
}

<?php

namespace App\Channels;

use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

abstract class AbstractChannelProvider implements NotificationChannelInterface
{
    public function send(Notification $notification): DeliveryResult
    {
        $url = config('notifications.webhook.url') . '/' . config('notifications.webhook.uuid');
        $payload = $this->formatPayload($notification);

        try {
            $response = Http::timeout(10)->post($url, $payload);
        } catch (ConnectionException $e) {
            return DeliveryResult::failure($e->getMessage(), true);
        }

        if ($response->status() === 202) {
            return DeliveryResult::successful($response->json('messageId'));
        }

        $isRetryable = $response->serverError();

        return DeliveryResult::failure(
            $response->json('error', 'Unknown error'),
            $isRetryable,
        );
    }

    /** @return array<string, string> */
    abstract protected function formatPayload(Notification $notification): array;
}

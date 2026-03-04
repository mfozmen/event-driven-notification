<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Support\Str;

class NotificationService
{
    public function create(array $data): array
    {
        $existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null);

        if ($existing) {
            return ['notification' => $existing, 'existed' => true];
        }

        $notification = Notification::create([
            'recipient' => $data['recipient'],
            'channel' => $data['channel'],
            'content' => $data['content'],
            'priority' => $data['priority'] ?? Priority::NORMAL->value,
            'status' => Status::PENDING,
            'correlation_id' => Str::orderedUuid(),
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        return ['notification' => $notification, 'existed' => false];
    }

    private function findByIdempotencyKey(?string $key): ?Notification
    {
        if (! $key) {
            return null;
        }

        return Notification::where('idempotency_key', $key)->first();
    }
}

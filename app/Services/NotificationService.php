<?php

namespace App\Services;

use App\DTOs\CreateNotificationResult;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;

class NotificationService
{
    private const DEFAULT_PER_PAGE = 15;

    public function list(array $filters): array
    {
        $perPage = $filters['per_page'] ?? self::DEFAULT_PER_PAGE;

        $query = Notification::query()
            ->orderBy('id', 'desc');

        $this->applyFilters($query, $filters);
        $this->applyCursor($query, $filters['cursor'] ?? null);

        $notifications = $query->limit($perPage + 1)->get();

        $nextCursor = $this->resolveNextCursor($notifications, $perPage);

        return [
            'notifications' => $notifications,
            'per_page' => $perPage,
            'next_cursor' => $nextCursor,
        ];
    }

    public function create(array $data): CreateNotificationResult
    {
        $existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null);

        if ($existing) {
            return new CreateNotificationResult($existing, existed: true);
        }

        $notification = Notification::create([
            'recipient' => $data['recipient'],
            'channel' => $data['channel'],
            'content' => $data['content'],
            'priority' => $data['priority'] ?? Priority::NORMAL->value,
            'status' => Status::PENDING,
            'correlation_id' => $data['correlation_id'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        return new CreateNotificationResult($notification, existed: false);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['channel']), fn (Builder $q) => $q->where('channel', $filters['channel']))
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['date_to']));
    }

    private function applyCursor(Builder $query, ?string $cursor): void
    {
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }
    }

    private function resolveNextCursor($notifications, int $perPage): ?string
    {
        if ($notifications->count() <= $perPage) {
            return null;
        }

        $notifications->pop();

        return $notifications->last()->id;
    }

    private function findByIdempotencyKey(?string $key): ?Notification
    {
        if (! $key) {
            return null;
        }

        return Notification::where('idempotency_key', $key)->first();
    }
}

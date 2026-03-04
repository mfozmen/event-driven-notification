<?php

namespace App\Services;

use App\DTOs\CreateNotificationResult;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

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

    /**
     * @param  array<string, mixed>  $data
     */
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

    public function cancel(Notification $notification): Notification
    {
        if (! $this->isCancellable($notification)) {
            abort(409, 'Notification cannot be cancelled in its current status.');
        }

        $notification->update(['status' => Status::CANCELLED]);

        return $notification;
    }

    private function isCancellable(Notification $notification): bool
    {
        return in_array($notification->status, [
            Status::PENDING,
            Status::QUEUED,
            Status::RETRYING,
        ]);
    }

    /**
     * @param  Builder<Notification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['channel']), fn (Builder $q) => $q->where('channel', $filters['channel']))
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['date_to']));
    }

    /**
     * @param  Builder<Notification>  $query
     */
    private function applyCursor(Builder $query, ?string $cursor): void
    {
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }
    }

    /**
     * @param  Collection<int, Notification>  $notifications
     */
    private function resolveNextCursor(Collection $notifications, int $perPage): ?string
    {
        if ($notifications->count() <= $perPage) {
            return null;
        }

        $notifications->pop();

        return $notifications->last()?->id;
    }

    private function findByIdempotencyKey(?string $key): ?Notification
    {
        if (! $key) {
            return null;
        }

        return Notification::where('idempotency_key', $key)->first();
    }
}

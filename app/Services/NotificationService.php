<?php

namespace App\Services;

use App\DTOs\BatchStatusResult;
use App\DTOs\CreateBatchResult;
use App\DTOs\CreateNotificationResult;
use App\Enums\Priority;
use App\Enums\Status;
use App\Events\NotificationCreated;
use App\Events\NotificationStatusUpdated;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationService
{
    private const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private NotificationLogger $logger,
    ) {}

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

        $content = $data['content'] ?? null;
        $templateId = $data['template_id'] ?? null;
        $templateVariables = $data['template_variables'] ?? [];

        if ($templateId) {
            $template = NotificationTemplate::findOrFail($templateId);

            try {
                $content = $template->render($templateVariables);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'template_variables' => [$e->getMessage()],
                ]);
            }
        }

        $notification = Notification::create([
            'recipient' => $data['recipient'],
            'channel' => $data['channel'],
            'content' => $content,
            'priority' => $data['priority'] ?? Priority::NORMAL->value,
            'status' => Status::PENDING,
            'correlation_id' => $data['correlation_id'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'template_id' => $templateId,
            'template_variables' => $templateId ? $templateVariables : null,
        ]);

        $this->logger->log($notification, 'created');

        NotificationCreated::dispatch($notification);

        return new CreateNotificationResult($notification, existed: false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $notifications
     */
    public function createBatch(array $notifications, string $correlationId): CreateBatchResult
    {
        $batchId = Str::orderedUuid()->toString();

        DB::transaction(function () use ($notifications, $batchId, $correlationId) {
            collect($notifications)->chunk(100)->each(function ($chunk) use ($batchId, $correlationId) {
                $records = $chunk->map(fn (array $item) => [
                    'id' => Str::orderedUuid()->toString(),
                    'batch_id' => $batchId,
                    'recipient' => $item['recipient'],
                    'channel' => $item['channel'],
                    'content' => $item['content'],
                    'priority' => $item['priority'] ?? Priority::NORMAL->value,
                    'status' => Status::PENDING->value,
                    'correlation_id' => $correlationId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                Notification::insert($records);
            });
        });

        $this->dispatchBatchEvents($batchId);

        return new CreateBatchResult($batchId, count($notifications));
    }

    public function batchStatus(string $batchId): ?BatchStatusResult
    {
        $notifications = Notification::where('batch_id', $batchId)->get();

        if ($notifications->isEmpty()) {
            return null;
        }

        $statusCounts = $notifications
            ->groupBy(fn (Notification $n) => $n->status->value)
            ->map->count()
            ->all();

        return new BatchStatusResult($batchId, $notifications->count(), $statusCounts);
    }

    public function cancel(Notification $notification): Notification
    {
        if (! $this->isCancellable($notification)) {
            abort(409, 'Notification cannot be cancelled in its current status.');
        }

        $notification->update(['status' => Status::CANCELLED]);

        $this->logger->log($notification, 'cancelled');

        try {
            NotificationStatusUpdated::dispatch($notification);
        } catch (\Throwable) {
            // Broadcasting failure should not affect cancellation
        }

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

    private function dispatchBatchEvents(string $batchId): void
    {
        Notification::where('batch_id', $batchId)
            ->each(fn (Notification $notification) => NotificationCreated::dispatch($notification));
    }

    private function findByIdempotencyKey(?string $key): ?Notification
    {
        if (! $key) {
            return null;
        }

        return Notification::where('idempotency_key', $key)->first();
    }
}

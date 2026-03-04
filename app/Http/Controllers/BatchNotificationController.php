<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBatchNotificationRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class BatchNotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function store(StoreBatchNotificationRequest $request): JsonResponse
    {
        $result = $this->notificationService->createBatch(
            $request->validated('notifications'),
            $request->attributes->get('correlation_id'),
        );

        return response()->json([
            'data' => [
                'batch_id' => $result->batchId,
                'count' => $result->count,
            ],
        ], 201);
    }

    public function show(string $batchId): JsonResponse
    {
        $result = $this->notificationService->batchStatus($batchId);

        if (! $result) {
            abort(404, 'Batch not found.');
        }

        return response()->json([
            'data' => [
                'batch_id' => $result->batchId,
                'total' => $result->total,
                'status_counts' => $result->statusCounts,
            ],
        ]);
    }
}

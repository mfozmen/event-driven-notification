<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBatchNotificationRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class BatchNotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    #[OA\Post(
        path: '/api/notifications/batch',
        summary: 'Create a batch of notifications',
        tags: ['Batch'],
        description: 'Create up to 1000 notifications in a single request.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['notifications'],
                properties: [
                    new OA\Property(
                        property: 'notifications',
                        type: 'array',
                        items: new OA\Items(
                            required: ['recipient', 'channel', 'content'],
                            properties: [
                                new OA\Property(property: 'recipient', type: 'string', example: '+905551234567'),
                                new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email', 'push']),
                                new OA\Property(property: 'content', type: 'string', example: 'Hello!'),
                                new OA\Property(property: 'priority', type: 'string', enum: ['high', 'normal', 'low'], nullable: true),
                            ]
                        ),
                        maxItems: 1000
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Batch created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/notifications/batch/{batchId}',
        summary: 'Get batch status',
        tags: ['Batch'],
        description: 'Returns total count and per-status breakdown for a batch.',
        parameters: [
            new OA\Parameter(name: 'batchId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Batch status'),
            new OA\Response(response: 404, description: 'Batch not found'),
        ]
    )]
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

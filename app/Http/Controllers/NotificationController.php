<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    #[OA\Get(
        path: '/api/notifications',
        summary: 'List notifications',
        tags: ['Notifications'],
        description: 'List notifications with optional filters and cursor-based pagination.',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'queued', 'processing', 'delivered', 'failed', 'retrying', 'permanently_failed', 'cancelled'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sms', 'email', 'push'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string', nullable: true)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of notifications'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $result = $this->notificationService->list($request->validated());

        return NotificationResource::collection($result['notifications'])
            ->additional([
                'meta' => [
                    'per_page' => $result['per_page'],
                    'next_cursor' => $result['next_cursor'],
                ],
            ]);
    }

    #[OA\Post(
        path: '/api/notifications',
        summary: 'Create a notification',
        tags: ['Notifications'],
        description: 'Create a single notification. Returns 200 with existing notification if idempotency_key is duplicate.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['recipient', 'channel', 'content'],
                properties: [
                    new OA\Property(property: 'recipient', type: 'string', example: '+905551234567'),
                    new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email', 'push']),
                    new OA\Property(property: 'content', type: 'string', example: 'Hello, world!'),
                    new OA\Property(property: 'priority', type: 'string', enum: ['high', 'normal', 'low'], nullable: true),
                    new OA\Property(property: 'idempotency_key', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Notification created'),
            new OA\Response(response: 200, description: 'Duplicate idempotency key — existing notification returned'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $data = array_merge($request->validated(), [
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);

        $result = $this->notificationService->create($data);

        $statusCode = $result->existed ? 200 : 201;

        return (new NotificationResource($result->notification))
            ->response()
            ->setStatusCode($statusCode);
    }

    #[OA\Get(
        path: '/api/notifications/{id}',
        summary: 'Get notification by ID',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification details'),
            new OA\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function show(Notification $notification): NotificationResource
    {
        return new NotificationResource($notification);
    }

    #[OA\Patch(
        path: '/api/notifications/{id}/cancel',
        summary: 'Cancel a notification',
        tags: ['Notifications'],
        description: 'Cancel a notification in pending, queued, or retrying status.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification cancelled'),
            new OA\Response(response: 404, description: 'Notification not found'),
            new OA\Response(response: 409, description: 'Notification cannot be cancelled'),
        ]
    )]
    public function cancel(Notification $notification): NotificationResource
    {
        $notification = $this->notificationService->cancel($notification);

        return new NotificationResource($notification);
    }
}

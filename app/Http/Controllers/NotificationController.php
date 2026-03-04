<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

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

    public function show(Notification $notification): NotificationResource
    {
        return new NotificationResource($notification);
    }
}

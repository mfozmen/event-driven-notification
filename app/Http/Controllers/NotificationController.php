<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function store(StoreNotificationRequest $request)
    {
        $result = $this->notificationService->create($request->validated());

        $statusCode = $result['existed'] ? 200 : 201;

        return (new NotificationResource($result['notification']))
            ->response()
            ->setStatusCode($statusCode);
    }

    public function show(Notification $notification)
    {
        return new NotificationResource($notification);
    }
}

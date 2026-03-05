<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationTemplateRequest;
use App\Http\Requests\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NotificationTemplateResource::collection(
            NotificationTemplate::all()
        );
    }

    public function store(StoreNotificationTemplateRequest $request): JsonResponse
    {
        $template = NotificationTemplate::create($request->validated());

        return (new NotificationTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    public function show(NotificationTemplate $template): NotificationTemplateResource
    {
        return new NotificationTemplateResource($template);
    }

    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template): NotificationTemplateResource
    {
        $template->update($request->validated());

        return new NotificationTemplateResource($template);
    }

    public function destroy(NotificationTemplate $template): JsonResponse
    {
        if (Notification::where('template_id', $template->id)->exists()) {
            abort(409, 'Template is referenced by existing notifications and cannot be deleted.');
        }

        $template->delete();

        return response()->json(null, 204);
    }
}

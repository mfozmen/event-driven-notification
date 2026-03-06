<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationTemplateRequest;
use App\Http\Requests\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class NotificationTemplateController extends Controller
{
    #[OA\Get(
        path: '/api/templates',
        summary: 'List templates',
        tags: ['Templates'],
        description: 'Returns all notification templates.',
        responses: [
            new OA\Response(response: 200, description: 'List of templates'),
        ]
    )]
    public function index(): AnonymousResourceCollection
    {
        return NotificationTemplateResource::collection(
            NotificationTemplate::all()
        );
    }

    #[OA\Post(
        path: '/api/templates',
        summary: 'Create a template',
        tags: ['Templates'],
        description: 'Create a new notification template.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'channel', 'body_template', 'variables'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'welcome-sms'),
                    new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email', 'push']),
                    new OA\Property(property: 'body_template', type: 'string', example: 'Hello {{name}}, welcome to {{company}}!'),
                    new OA\Property(property: 'variables', type: 'array', items: new OA\Items(type: 'string'), example: ['name', 'company']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Template created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreNotificationTemplateRequest $request): JsonResponse
    {
        $template = NotificationTemplate::create($request->validated());

        return (new NotificationTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    // NOSONAR — Swagger annotation paths are intentionally repeated for OpenAPI spec readability
    #[OA\Get(
        path: '/api/templates/{template}',
        summary: 'Get template by ID',
        tags: ['Templates'],
        parameters: [
            new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Template details'),
            new OA\Response(response: 404, description: 'Template not found'),
        ]
    )]
    public function show(NotificationTemplate $template): NotificationTemplateResource
    {
        return new NotificationTemplateResource($template);
    }

    #[OA\Put(
        path: '/api/templates/{template}',
        summary: 'Update a template',
        tags: ['Templates'],
        parameters: [
            new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'channel', 'body_template', 'variables'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'welcome-sms-v2'),
                    new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email', 'push']),
                    new OA\Property(property: 'body_template', type: 'string', example: 'Hi {{name}}, thanks for joining {{company}}!'),
                    new OA\Property(property: 'variables', type: 'array', items: new OA\Items(type: 'string'), example: ['name', 'company']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Template updated'),
            new OA\Response(response: 404, description: 'Template not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template): NotificationTemplateResource
    {
        $template->update($request->validated());

        return new NotificationTemplateResource($template);
    }

    #[OA\Delete(
        path: '/api/templates/{template}',
        summary: 'Delete a template',
        tags: ['Templates'],
        parameters: [
            new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Template deleted'),
            new OA\Response(response: 404, description: 'Template not found'),
            new OA\Response(response: 409, description: 'Template is referenced by existing notifications'),
        ]
    )]
    public function destroy(NotificationTemplate $template): JsonResponse
    {
        if (Notification::where('template_id', $template->id)->exists()) {
            abort(409, 'Template is referenced by existing notifications and cannot be deleted.');
        }

        $template->delete();

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Event-Driven Notification API',
    description: 'A scalable notification system that processes and delivers messages through SMS, Email, and Push channels.',
)]
#[OA\Server(url: 'http://localhost:8080', description: 'Local')]
#[OA\Tag(name: 'Notifications', description: 'Single notification operations')]
#[OA\Tag(name: 'Batch', description: 'Batch operations')]
#[OA\Tag(name: 'Templates', description: 'Notification template operations')]
#[OA\Tag(name: 'Observability', description: 'Health check and metrics')]
abstract class Controller
{
    //
}

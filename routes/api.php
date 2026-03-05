<?php

use App\Http\Controllers\BatchNotificationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationController;
use App\Http\Middleware\CorrelationIdMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->withoutMiddleware(CorrelationIdMiddleware::class);
Route::get('/metrics', MetricsController::class);

Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications', [NotificationController::class, 'store']);
Route::post('/notifications/batch', [BatchNotificationController::class, 'store']);
Route::get('/notifications/batch/{batchId}', [BatchNotificationController::class, 'show']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::patch('/notifications/{notification}/cancel', [NotificationController::class, 'cancel']);

<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications', [NotificationController::class, 'store']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::patch('/notifications/{notification}/cancel', [NotificationController::class, 'cancel']);

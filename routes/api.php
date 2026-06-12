<?php

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\GuestApiController;
use App\Http\Controllers\Api\V1\ReservationApiController;
use App\Http\Controllers\Api\V1\WebhookApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['auth:sanctum', \App\Http\Middleware\ResolveApiTenant::class, 'throttle:api'])
    ->group(function () {
        Route::get('/availability', [AvailabilityController::class, 'index']);

        Route::get('/reservations', [ReservationApiController::class, 'index']);
        Route::post('/reservations', [ReservationApiController::class, 'store']);
        Route::get('/reservations/{code}', [ReservationApiController::class, 'show']);
        Route::post('/reservations/{code}/cancel', [ReservationApiController::class, 'cancel']);

        Route::get('/guests', [GuestApiController::class, 'index']);
        Route::get('/guests/{guest}', [GuestApiController::class, 'show']);

        Route::get('/webhooks', [WebhookApiController::class, 'index']);
        Route::post('/webhooks', [WebhookApiController::class, 'store']);
        Route::delete('/webhooks/{endpoint}', [WebhookApiController::class, 'destroy']);
    });

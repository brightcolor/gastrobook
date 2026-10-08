<?php

declare(strict_types=1);

use App\Http\Controllers\Provisioning\ProvisioningController;
use Illuminate\Support\Facades\Route;

/*
 * Bereitstellungs-Schnittstelle fuer das Kundenpanel, Version 1.
 *
 * OHNE WEB-MIDDLEWARE: keine Sitzung, kein Cookie, kein CSRF, kein Cap. Die
 * Aufrufe sind signiert (App\Provisioning\RequestCheck). Die Pfade liegen
 * direkt unter der Wurzel und antworten ohne Umleitung; das Panel folgt keiner.
 */
Route::prefix('provisioning/v1')->group(function () {
    Route::get('/info', [ProvisioningController::class, 'info']);
    Route::post('/accounts', [ProvisioningController::class, 'create']);
    Route::get('/accounts/{contract}', [ProvisioningController::class, 'status']);
    Route::put('/accounts/{contract}/tariff', [ProvisioningController::class, 'tariff']);
    Route::post('/accounts/{contract}/{action}', [ProvisioningController::class, 'action']);
    Route::delete('/accounts/{contract}', [ProvisioningController::class, 'delete']);
});

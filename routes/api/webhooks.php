<?php

use App\Http\Controllers\Webhooks\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Rutas para gestión de webhooks.
| Requiere autenticación JWT.
|
*/

Route::middleware(['auth:api'])->group(function () {
    // CRUD de webhooks
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{id}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::put('/webhooks/{id}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{id}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');

    // Rotación de secret
    Route::post('/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.rotate-secret');

    // Historial de entregas
    Route::get('/webhooks/{id}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');

    // Reenvío manual de entregas fallidas
    Route::post('/webhooks/{id}/deliveries/{deliveryId}/retry', [WebhookController::class, 'retryDelivery'])->name('webhooks.deliveries.retry');
});

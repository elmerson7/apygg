<?php

use App\Http\Controllers\ApiKeys\ApiKeyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Keys Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con gestión de API Keys.
| Todas las rutas aquí requieren autenticación JWT.
|
*/

Route::middleware(['auth:api'])->prefix('api-keys')->group(function () {
    // Rutas CRUD básicas
    Route::get('/', [ApiKeyController::class, 'index'])
        ->name('api-keys.index');

    Route::get('/{id}', [ApiKeyController::class, 'show'])
        ->name('api-keys.show');

    Route::post('/', [ApiKeyController::class, 'store'])
        ->name('api-keys.store');

    Route::delete('/{id}', [ApiKeyController::class, 'destroy'])
        ->name('api-keys.destroy');

    // Rotación de keys
    Route::post('/{id}/rotate', [ApiKeyController::class, 'rotate'])
        ->name('api-keys.rotate');
});

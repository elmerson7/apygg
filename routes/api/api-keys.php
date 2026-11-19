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

/*
| Ejemplo del mecanismo para integraciones máquina a máquina: autenticación con API Key
| (`auth:api-key`, guard `api-key`) + scope (`api-key-scope:`). Úsalo como plantilla para
| exponer rutas a integraciones; ver docs/api-keys.md.
*/
Route::middleware(['auth:api-key', 'api-key-scope:profile.read', 'throttle:60,1'])
    ->get('/api-key/whoami', [ApiKeyController::class, 'whoami'])
    ->name('api-key.whoami');

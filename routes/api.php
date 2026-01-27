<?php

use App\Http\Controllers\TestSentryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Todas las rutas aquí son para API únicamente.
| Rutas directas en la raíz (sin prefijo /api ni versión)
| Todas las respuestas son JSON (forzado por ForceJsonResponse middleware)
|
| Estructura modular: cada módulo tiene su archivo en routes/api/
|
*/

// Ruta raíz: información de la API
Route::get('/', function () {
    return response()->json([
        'name' => 'APYGG API',
        'status' => 'running',
        'version' => config('app.version', '1.0.0'),
        'endpoints' => [
            'health' => '/health',
            'health_live' => '/health/live',
            'health_ready' => '/health/ready',
            'health_detailed' => '/health/detailed',
            'search' => '/search',
            'webhooks' => '/webhooks',
            'documentation' => '/docs/api', // Scramble API Documentation
        ],
    ]);
});

// Health check endpoints (sin autenticación)
use App\Http\Controllers\Health\HealthController;

Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::middleware(['auth:api'])->get('/health/detailed', [HealthController::class, 'detailed']);

// Cargar rutas modulares
require __DIR__.'/api/auth.php';
require __DIR__.'/api/users.php';
require __DIR__.'/api/api-keys.php';
require __DIR__.'/api/files.php';
require __DIR__.'/api/search.php';
require __DIR__.'/api/webhooks.php';

Route::prefix('test/sentry')->group(function () {
    Route::get('/info', [TestSentryController::class, 'info']);
    Route::post('/logs', [TestSentryController::class, 'testLogs']);
    Route::post('/exception', [TestSentryController::class, 'testException']);
});

// Rutas con API Key (para servicios/integraciones)
// Route::middleware(['auth:api-key'])->group(function () {
//     Route::prefix('webhooks')->group(function () {
//         // Webhooks para integraciones
//     });
// });

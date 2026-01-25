<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestSentryController;

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
        'endpoints' => [
            'health' => '/health',
            'documentation' => '/documentation', // Cuando implementes Scramble
        ],
    ]);
});

// Health check (sin autenticación)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Cargar rutas modulares
require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/users.php';

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

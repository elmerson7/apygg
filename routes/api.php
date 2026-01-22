<?php

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

// Ruta de prueba de Sentry (solo para testing, eliminar en producción)
Route::get('/test-sentry', function () {
    if (!config('app.debug')) {
        return response()->json(['error' => 'Not available in production'], 404);
    }

    // Prueba 1: Excepción manual
    try {
        throw new \Exception('Test exception for Sentry integration');
    } catch (\Exception $e) {
        \Sentry\captureException($e);
    }

    // Prueba 2: Mensaje manual
    \Sentry\captureMessage('Test message for Sentry', \Sentry\Severity::info());

    // Prueba 3: LogService
    \App\Infrastructure\Services\LogService::error('Test error via LogService', [
        'test' => true,
        'route' => '/test-sentry',
    ]);

    return response()->json([
        'message' => 'Sentry test events sent',
        'check_sentry_dashboard' => true,
    ]);
});

// Rutas públicas de autenticación (sin autenticación requerida)
use App\Modules\Auth\Controllers\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    // Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    // Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Rutas protegidas de autenticación (requieren autenticación JWT)
Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
});

// Rutas protegidas (requieren autenticación JWT)
// Route::middleware(['auth:api'])->group(function () {
//     Route::prefix('users')->group(function () {
//         Route::get('/', [UserController::class, 'index']);
//         Route::get('/{id}', [UserController::class, 'show']);
//         Route::put('/{id}', [UserController::class, 'update']);
//         Route::delete('/{id}', [UserController::class, 'destroy']);
//     });
// });

// Rutas con API Key (para servicios/integraciones)
// Route::middleware(['auth:api-key'])->group(function () {
//     Route::prefix('webhooks')->group(function () {
//         // Webhooks para integraciones
//     });
// });

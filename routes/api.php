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

// Rutas públicas (sin autenticación)
// Route::prefix('auth')->group(function () {
//     Route::post('/login', [AuthController::class, 'login']);
//     Route::post('/register', [AuthController::class, 'register']);
//     Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
//     Route::post('/reset-password', [AuthController::class, 'resetPassword']);
// });

// Rutas protegidas (requieren autenticación JWT)
// Route::middleware(['auth:api'])->group(function () {
//     Route::prefix('users')->group(function () {
//         Route::get('/', [UserController::class, 'index']);
//         Route::get('/{id}', [UserController::class, 'show']);
//         Route::put('/{id}', [UserController::class, 'update']);
//         Route::delete('/{id}', [UserController::class, 'destroy']);
//     });
//
//     Route::post('/auth/logout', [AuthController::class, 'logout']);
//     Route::get('/auth/me', [AuthController::class, 'me']);
// });

// Rutas con API Key (para servicios/integraciones)
// Route::middleware(['auth:api-key'])->group(function () {
//     Route::prefix('webhooks')->group(function () {
//         // Webhooks para integraciones
//     });
// });

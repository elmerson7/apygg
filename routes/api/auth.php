<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con autenticación de usuarios.
| Rate limiting adaptativo aplicado automáticamente:
| - Endpoints públicos (login, register, password reset): 5 por minuto por IP
| - Endpoints protegidos: según tipo (read/write/admin)
|
*/

// Rutas públicas de autenticación (sin autenticación requerida)
// Rate limiting adaptativo: 5 intentos por minuto por IP (configurado en AdaptiveRateLimitingMiddleware)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordController::class, 'resetPassword']);
});

// Rutas protegidas de autenticación (requieren autenticación JWT)
// Rate limiting adaptativo aplicado automáticamente según tipo de endpoint
Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [PasswordController::class, 'changePassword']);
});

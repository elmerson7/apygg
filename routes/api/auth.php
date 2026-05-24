<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\BroadcastAuthController;
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
// Incluye /refresh porque el access token ya expiró y no puede autenticar
// La validación del refresh token se hace internamente en TokenService
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordController::class, 'resetPassword']);
});

// Broadcasting auth (WebSockets - OPCIONAL)
// Solo funciona si BROADCAST_CONNECTION=reverb está configurado
// NOTA: Laravel también crea una ruta automática en routes/web.php
// Esta ruta usa el mismo endpoint pero con autenticación JWT para API
Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate'])
    ->middleware('auth:api')
    ->name('broadcasting.auth');

// Rutas protegidas de autenticación (requieren autenticación JWT)
Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [PasswordController::class, 'changePassword']);
});

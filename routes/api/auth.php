<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con autenticación de usuarios.
| Rate limiting: 5 requests por minuto por IP para endpoints públicos.
|
*/

// Rutas públicas de autenticación (sin autenticación requerida)
// Rate limiting: 5 intentos por minuto por IP para prevenir ataques de fuerza bruta
Route::prefix('auth')->middleware(['throttle:5,1'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordController::class, 'resetPassword']);
});

// Rutas protegidas de autenticación (requieren autenticación JWT)
// Rate limiting: 30 requests por minuto por usuario autenticado
Route::middleware(['auth:api', 'throttle:30,1'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [PasswordController::class, 'changePassword']);
});

<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\BroadcastAuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
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
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])->name('auth.2fa.verify');
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
});

// module:reverb:start
// Broadcasting auth (WebSockets - OPCIONAL)
// Solo funciona si BROADCAST_CONNECTION=reverb está configurado
// NOTA: Laravel también crea una ruta automática en routes/web.php
// Esta ruta usa el mismo endpoint pero con autenticación JWT para API
Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate'])
    ->middleware('auth:api')
    ->name('broadcasting.auth');
// module:reverb:end

// Rutas protegidas de autenticación (requieren autenticación JWT)
Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [PasswordController::class, 'changePassword']);
});

// Reenvío del correo de verificación (límite por usuario y hora en el servicio)
Route::middleware(['auth:api'])->post('/auth/email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('idempotent')->name('auth.email.resend');

// Sesiones (dispositivos con login abierto) y 2FA del usuario en sesión
Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('sessions', [SessionController::class, 'index'])->name('user.sessions.index');
    Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('user.sessions.destroy-others');
    Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->whereUuid('id')->name('user.sessions.destroy');

    Route::get('2fa', [TwoFactorController::class, 'show'])->name('user.2fa.show');
    Route::post('2fa/setup', [TwoFactorController::class, 'setup'])->name('user.2fa.setup');
    Route::post('2fa/confirm', [TwoFactorController::class, 'confirm'])->name('user.2fa.confirm');
    Route::post('2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('user.2fa.recovery-codes');
    Route::delete('2fa', [TwoFactorController::class, 'destroy'])->name('user.2fa.destroy');
});

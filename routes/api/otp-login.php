<?php

use App\Http\Controllers\Auth\OtpLoginController;
use Illuminate\Support\Facades\Route;

/*
| Login por código de un solo uso (módulo `otp-login`, docs/otp-login.md). Público.
| Apagado por defecto (`OTP_LOGIN_ENABLED`): sin activar responde 404 `otp_login_unavailable`.
*/
Route::prefix('auth/otp')->group(function () {
    Route::post('/request', [OtpLoginController::class, 'request'])->name('auth.otp.request');
    Route::post('/verify', [OtpLoginController::class, 'verify'])->name('auth.otp.verify');
});

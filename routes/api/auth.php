<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login',   [AuthController::class, 'login'])->name('login');

        // refresh con rotación y blacklist (sin middleware jwt.refresh)
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

        // Social login (si aplica)
        // Route::post('google',  [SocialAuthController::class, 'google'])->name('google');
        // Route::post('facebook',[SocialAuthController::class, 'facebook'])->name('facebook');
    });

    Route::middleware(['jwt'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SocialAuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login',   [AuthController::class, 'login'])->name('login');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('jwt.refresh')->name('refresh');
        Route::post('google',  [SocialAuthController::class, 'google'])->name('google');
        Route::post('facebook',[SocialAuthController::class, 'facebook'])->name('facebook');
    });

    Route::middleware(['jwt'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

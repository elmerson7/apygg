<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Users\UserController;

Route::prefix('users')->name('users.')->group(function () {
    Route::middleware(['jwt', 'throttle:users'])->group(function () {
        Route::get('me', [UserController::class, 'me'])->name('me');
        Route::get('{user}', [UserController::class, 'show'])
            ->whereUuid('user')->name('show');
        Route::patch('{user}', [UserController::class, 'update'])
            ->whereUuid('user')->name('update');
    });
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Swipes\SwipeController;

Route::prefix('swipes')->name('swipes.')
    ->middleware(['jwt','throttle:matches','idempotency'])
    ->group(function () {
        Route::post('', [SwipeController::class, 'store'])->name('store'); // left/right/superlike
    });

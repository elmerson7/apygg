<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;

Route::prefix('profiles')->name('profiles.')
    ->middleware(['jwt','throttle:users'])
    ->group(function () {
        Route::get('me', [ProfileController::class, 'me'])->name('me');
    });
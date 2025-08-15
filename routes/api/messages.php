<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MessageController;

Route::prefix('chats')->name('chats.')
    ->middleware(['jwt','throttle:users'])
    ->group(function () {
        Route::get('{match}/messages', [MessageController::class, 'index'])
            ->whereUuid('match')->name('messages.index');
        Route::post('{match}/messages', [MessageController::class, 'store'])
            ->middleware('idempotency')->whereUuid('match')->name('messages.store');
    });

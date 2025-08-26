<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Gifts\GiftController;

Route::middleware(['jwt', 'throttle:users'])
    ->apiResource('gifts', GiftController::class);  
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GiftController;

Route::middleware(['jwt', 'throttle:users'])
    ->apiResource('gifts', GiftController::class);  
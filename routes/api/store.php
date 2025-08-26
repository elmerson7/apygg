<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Store\StoreController;

Route::middleware(['jwt', 'throttle:users'])
    ->apiResource('store', StoreController::class);  
# compras, monedas, subscriptions
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminController;

Route::middleware(['jwt', 'throttle:admin'])
    ->apiResource('admin', AdminController::class);  
# users, matches, messages, gifts, store, achievements, webhooks
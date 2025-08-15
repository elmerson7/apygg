<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AchievementController;

Route::middleware(['jwt', 'throttle:users'])
    ->apiResource('achievements', AchievementController::class);  
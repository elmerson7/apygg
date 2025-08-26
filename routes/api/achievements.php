<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Achievements\AchievementController;

Route::middleware(['jwt', 'throttle:users'])
    ->apiResource('achievements', AchievementController::class);  
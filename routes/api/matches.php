<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Matches\MatchController;

Route::middleware(['jwt', 'throttle:matches'])
    ->apiResource('users.matches', MatchController::class)
    ->scoped(['match' => 'uuid']); // asegura pertenencia del hijo al padre

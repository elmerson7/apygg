<?php

use App\Http\Controllers\Chat\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chat Routes
|--------------------------------------------------------------------------
|
| Rutas para el chat en tiempo real.
| No requiere autenticación para facilitar pruebas.
|
*/

Route::prefix('chat')->group(function () {
    Route::post('/send', [ChatController::class, 'send'])
        ->name('chat.send');
});

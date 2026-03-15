<?php

use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User (current user) Routes
|--------------------------------------------------------------------------
|
| Rutas del usuario autenticado: perfil, preferencias, etc.
| Prefijo /user para coincidir con la convención del proyecto.
|
*/

Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('profile', [UserController::class, 'showProfile'])->name('user.profile.show');
    Route::put('profile', [UserController::class, 'updateProfile'])->name('user.profile.update');
    Route::put('preferences', [UserController::class, 'updatePreferences'])->name('user.preferences.update');
});

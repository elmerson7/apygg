<?php

use App\Http\Controllers\Users\ProfileController;
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
    // Profile
    Route::get('profile', [ProfileController::class, 'show'])->name('user.profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('user.profile.update');
    // Preferences
    Route::get('preferences', [ProfileController::class, 'showPreferences'])->name('user.preferences.show');
    Route::put('preferences', [ProfileController::class, 'updatePreferences'])->name('user.preferences.update');
});

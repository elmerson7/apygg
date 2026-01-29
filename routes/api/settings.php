<?php

use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con gestión de Settings.
| Todas las rutas aquí requieren autenticación JWT.
| Solo administradores pueden gestionar settings.
|
*/

Route::middleware(['auth:api'])->prefix('settings')->group(function () {
    // CRUD básico
    Route::get('/', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::get('/{id}', [SettingsController::class, 'show'])
        ->name('settings.show');

    Route::post('/', [SettingsController::class, 'store'])
        ->name('settings.store');

    Route::put('/{id}', [SettingsController::class, 'update'])
        ->name('settings.update');

    Route::delete('/{id}', [SettingsController::class, 'destroy'])
        ->name('settings.destroy');

    // Endpoint especial: obtener por key
    Route::get('/key/{key}', [SettingsController::class, 'getByKey'])
        ->name('settings.getByKey');
});

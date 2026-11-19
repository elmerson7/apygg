<?php

use App\Http\Controllers\I18n\LocaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| i18n: idioma, zona horaria y moneda (docs/i18n.md)
|--------------------------------------------------------------------------
*/

// Preferencias propias del usuario (heredan del tenant/instalación si son null)
Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('locale', [LocaleController::class, 'showUser'])->name('user.locale.show');
    Route::put('locale', [LocaleController::class, 'updateUser'])->name('user.locale.update');
});

// Valores por defecto del tenant activo (multiempresa)
Route::middleware(['auth:api', 'tenant'])->prefix('tenant')->group(function () {
    Route::get('locale', [LocaleController::class, 'showTenant'])->name('tenant.locale.show');
    Route::put('locale', [LocaleController::class, 'updateTenant'])
        ->middleware('permission:tenant-settings.manage,api')
        ->name('tenant.locale.update');
});

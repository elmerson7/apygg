<?php

use App\Http\Controllers\Tenant\TenantFeaturesController;
use App\Http\Controllers\Tenant\TenantLimitsController;
use App\Http\Controllers\Tenant\TenantSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Ajustes, features y límites DEL TENANT ACTIVO (middleware `tenant`; docs/settings-and-flags.md).
*/
Route::middleware(['auth:api', 'tenant'])->prefix('tenant')->group(function () {
    Route::middleware('permission:tenant-settings.read,api')->group(function () {
        Route::get('/settings', [TenantSettingsController::class, 'index'])->name('tenant.settings.index');
        Route::get('/features', [TenantFeaturesController::class, 'index'])->name('tenant.features.index');
        Route::get('/limits', [TenantLimitsController::class, 'index'])->name('tenant.limits.index');
    });

    Route::middleware('permission:tenant-settings.manage,api')->group(function () {
        Route::put('/settings/{key}', [TenantSettingsController::class, 'update'])->name('tenant.settings.update');
        Route::delete('/settings/{key}', [TenantSettingsController::class, 'destroy'])->name('tenant.settings.destroy');
    });
});

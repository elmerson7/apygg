<?php

use App\Http\Controllers\Features\FeatureFlagController;
use Illuminate\Support\Facades\Route;

/*
| Feature flags de plataforma (docs/settings-and-flags.md). Las flags se declaran en config/features.php.
*/
Route::middleware(['auth:api'])->prefix('admin/feature-flags')->group(function () {
    Route::get('/', [FeatureFlagController::class, 'index'])
        ->middleware('permission:feature-flags.read,api')->name('feature-flags.index');

    Route::middleware('permission:feature-flags.manage,api')->group(function () {
        Route::put('/{key}', [FeatureFlagController::class, 'update'])->name('feature-flags.update');
        Route::delete('/{key}', [FeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');
        Route::put('/{key}/tenants/{tenantId}', [FeatureFlagController::class, 'setTenantOverride'])->whereNumber('tenantId')->name('feature-flags.tenant.set');
        Route::delete('/{key}/tenants/{tenantId}', [FeatureFlagController::class, 'clearTenantOverride'])->whereNumber('tenantId')->name('feature-flags.tenant.clear');
        Route::put('/{key}/users/{userId}', [FeatureFlagController::class, 'setUserOverride'])->whereNumber('userId')->name('feature-flags.user.set');
        Route::delete('/{key}/users/{userId}', [FeatureFlagController::class, 'clearUserOverride'])->whereNumber('userId')->name('feature-flags.user.clear');
    });
});

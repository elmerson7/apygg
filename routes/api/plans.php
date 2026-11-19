<?php

use App\Http\Controllers\Plans\TenantPlanController;
use Illuminate\Support\Facades\Route;

/*
| Plan y límites de un tenant, nivel plataforma (docs/settings-and-flags.md §Límites).
*/
Route::middleware(['auth:api', 'permission:plan-limits.manage,api'])->prefix('admin/tenants/{tenant}')->whereNumber('tenant')->group(function () {
    Route::get('/plan', [TenantPlanController::class, 'show'])->name('plans.show');
    Route::put('/plan', [TenantPlanController::class, 'updatePlan'])->name('plans.update');
    Route::put('/limits/{key}', [TenantPlanController::class, 'setLimit'])->name('plans.limits.set');
    Route::delete('/limits/{key}', [TenantPlanController::class, 'clearLimit'])->name('plans.limits.clear');
});

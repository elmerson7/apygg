<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\MaintenanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Impersonación de soporte (docs/impersonation.md) y modo mantenimiento (docs/maintenance-mode.md)
|--------------------------------------------------------------------------
|
| `stop` solo pide estar autenticado: lo llama el propio token de impersonación (que no tiene permisos de admin).
*/
Route::middleware(['auth:api'])->prefix('admin/impersonate')->group(function () {
    Route::post('/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');

    Route::post('/{id}', [ImpersonationController::class, 'start'])
        ->whereNumber('id')
        ->middleware('permission:users.impersonate,api')
        ->name('impersonation.start');
});

Route::middleware(['auth:api', 'permission:maintenance.manage,api'])->prefix('admin/maintenance')->group(function () {
    Route::get('/', [MaintenanceController::class, 'show'])->name('maintenance.show');
    Route::post('/', [MaintenanceController::class, 'enable'])->name('maintenance.enable');
    Route::delete('/', [MaintenanceController::class, 'disable'])->name('maintenance.disable');
});

<?php

use App\Http\Controllers\Admin\MaintenanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modo mantenimiento (docs/maintenance-mode.md)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'permission:maintenance.manage,api'])->prefix('admin/maintenance')->group(function () {
    Route::get('/', [MaintenanceController::class, 'show'])->name('maintenance.show');
    Route::post('/', [MaintenanceController::class, 'enable'])->name('maintenance.enable');
    Route::delete('/', [MaintenanceController::class, 'disable'])->name('maintenance.disable');
});

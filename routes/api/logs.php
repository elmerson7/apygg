<?php

use App\Http\Controllers\Logs\LogsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Logs Routes
|--------------------------------------------------------------------------
|
| Requiere auth:api. Los endpoints de listado requieren permiso system.logs.
| Los endpoints de limpieza requieren permiso system.logs-delete.
|
*/

Route::middleware(['auth:api'])->prefix('admin/logs')->group(function () {
    // Listado
    Route::middleware('permission:system.logs')->group(function () {
        Route::get('/activity', [LogsController::class, 'activity'])->name('logs.activity');
        Route::get('/api', [LogsController::class, 'api'])->name('logs.api');
        Route::get('/security', [LogsController::class, 'security'])->name('logs.security');
    });

    // Limpieza (permiso aparte: borrar logs es borrar rastro)
    Route::middleware('permission:system.logs-delete')->group(function () {
        Route::delete('/activity', [LogsController::class, 'clearActivity'])->name('logs.activity.clear');
        Route::delete('/api', [LogsController::class, 'clearApi'])->name('logs.api.clear');
        Route::delete('/security', [LogsController::class, 'clearSecurity'])->name('logs.security.clear');
    });
});

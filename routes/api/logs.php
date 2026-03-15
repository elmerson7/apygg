<?php

use App\Http\Controllers\Logs\LogsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Logs Routes
|--------------------------------------------------------------------------
|
| Requiere auth:api. Los endpoints de listado requieren permiso logs.view.
| Los endpoints de limpieza requieren permiso logs.delete.
|
*/

Route::middleware(['auth:api'])->prefix('admin/logs')->group(function () {
    // Listado
    Route::get('/activity', [LogsController::class, 'activity'])->name('logs.activity');
    Route::get('/api', [LogsController::class, 'api'])->name('logs.api');
    Route::get('/security', [LogsController::class, 'security'])->name('logs.security');

    // Limpieza (solo superadmin)
    Route::delete('/activity', [LogsController::class, 'clearActivity'])->name('logs.activity.clear');
    Route::delete('/api', [LogsController::class, 'clearApi'])->name('logs.api.clear');
    Route::delete('/security', [LogsController::class, 'clearSecurity'])->name('logs.security.clear');
});

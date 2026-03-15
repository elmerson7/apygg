<?php

use App\Http\Controllers\Roles\ActivityLogController;
use App\Http\Controllers\Roles\PermissionController;
use App\Http\Controllers\Roles\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Role Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con gestión de roles y permisos.
| Prefijo /admin según convención del proyecto.
|
*/

Route::middleware(['auth:api'])->prefix('admin')->group(function () {

    // ========== HISTORIAL DE CAMBIOS ==========
    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])
            ->middleware('permission:roles.read')
            ->name('activity-logs.index');
    });

    // ========== ROLES ==========
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])
            ->middleware('permission:roles.read')
            ->name('roles.index');

        Route::get('/{id}', [RoleController::class, 'show'])
            ->middleware('permission:roles.read')
            ->name('roles.show');

        Route::post('/', [RoleController::class, 'store'])
            ->middleware('permission:roles.create')
            ->name('roles.store');

        Route::put('/{id}', [RoleController::class, 'update'])
            ->middleware('permission:roles.update')
            ->name('roles.update');

        Route::delete('/{id}', [RoleController::class, 'destroy'])
            ->middleware('permission:roles.delete')
            ->name('roles.destroy');

        Route::get('/{id}/permissions', [RoleController::class, 'permissions'])
            ->middleware('permission:roles.read')
            ->name('roles.permissions');

        Route::put('/{id}/permissions', [RoleController::class, 'syncPermissions'])
            ->middleware('permission:roles.manage-permissions')
            ->name('roles.permissions.sync');
    });

    // ========== PERMISOS ==========
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])
            ->middleware('permission:permissions.read')
            ->name('permissions.index');

        Route::get('/{id}', [PermissionController::class, 'show'])
            ->middleware('permission:permissions.read')
            ->name('permissions.show');

        Route::post('/', [PermissionController::class, 'store'])
            ->middleware('permission:permissions.create')
            ->name('permissions.store');

        Route::put('/{id}', [PermissionController::class, 'update'])
            ->middleware('permission:permissions.update')
            ->name('permissions.update');

        Route::delete('/{id}', [PermissionController::class, 'destroy'])
            ->middleware('permission:permissions.delete')
            ->name('permissions.destroy');
    });
});

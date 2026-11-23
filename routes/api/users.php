<?php

use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con gestión de usuarios.
| Todas las rutas aquí requieren autenticación JWT.
|
*/

Route::middleware(['auth:api'])->prefix('users')->group(function () {
    // Rutas CRUD básicas
    Route::get('/', [UserController::class, 'index'])
        ->middleware('permission:users.read,api')
        ->name('users.index');

    Route::get('/{id}', [UserController::class, 'show'])
        ->name('users.show');

    Route::post('/', [UserController::class, 'store'])
        ->middleware('permission:users.create,api')
        ->middleware('idempotent')->name('users.store');

    // Sin middleware de permiso: cada quien edita su perfil; administrar a otros
    // lo decide UserPolicy::update (users.update). Roles y contraseña: ver controller.
    Route::put('/{id}', [UserController::class, 'update'])
        ->name('users.update');

    Route::delete('/{id}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete,api')
        ->name('users.destroy');

    // Permisos propios de la acción
    Route::post('/{id}/restore', [UserController::class, 'restore'])
        ->middleware('permission:users.restore,api')
        ->name('users.restore');

    Route::post('/{id}/roles', [UserController::class, 'assignRoles'])
        ->middleware('permission:users.manage-roles,api')
        ->name('users.assignRoles');

    Route::delete('/{id}/roles/{roleId}', [UserController::class, 'removeRole'])
        ->middleware('permission:users.manage-roles,api')
        ->name('users.removeRole');

    Route::get('/{id}/activity', [UserController::class, 'getActivity'])
        ->name('users.activity');
});

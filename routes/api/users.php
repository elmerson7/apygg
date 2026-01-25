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
        ->middleware('permission:users.read')
        ->name('users.index');

    Route::get('/{id}', [UserController::class, 'show'])
        ->name('users.show');

    Route::post('/', [UserController::class, 'store'])
        ->middleware('permission:users.create')
        ->name('users.store');

    Route::put('/{id}', [UserController::class, 'update'])
        ->name('users.update');

    Route::delete('/{id}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->name('users.destroy');

    // Rutas adicionales
    Route::post('/{id}/restore', [UserController::class, 'restore'])
        ->middleware('permission:users.restore')
        ->name('users.restore');

    Route::post('/{id}/roles', [UserController::class, 'assignRoles'])
        ->middleware('permission:users.update')
        ->name('users.assignRoles');

    Route::delete('/{id}/roles/{roleId}', [UserController::class, 'removeRole'])
        ->middleware('permission:users.update')
        ->name('users.removeRole');

    Route::get('/{id}/activity', [UserController::class, 'getActivity'])
        ->name('users.activity');
});

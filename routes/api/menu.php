<?php

use App\Http\Controllers\Menu\MenuController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Menu Routes
|--------------------------------------------------------------------------
|
| Menú dinámico del panel. El cliente pide su menú y renderiza lo que reciba;
| la decisión de visibilidad vive solo en el backend.
|
| El menú es PRESENTACIÓN, no autorización: ocultar un ítem no protege nada.
| Cada ruta sigue exigiendo su permiso.
|
*/

// Menú del usuario autenticado: cualquiera puede pedir el suyo.
Route::middleware('auth:api')
    ->get('/user/menu', [MenuController::class, 'mine'])
    ->name('menu.mine');

// Configuración del catálogo
Route::middleware(['auth:api', 'permission:menu.manage,api'])
    ->prefix('admin/menu')
    ->group(function () {
        Route::get('/items', [MenuController::class, 'catalog'])->name('menu.items.index');
        Route::post('/items', [MenuController::class, 'store'])->middleware('idempotent')->name('menu.items.store');
        Route::put('/items/visibility', [MenuController::class, 'updateVisibility'])->name('menu.items.visibility');
        Route::put('/items/{menuItem}', [MenuController::class, 'update'])->name('menu.items.update');
        Route::delete('/items/{menuItem}', [MenuController::class, 'destroy'])->name('menu.items.destroy');
    });

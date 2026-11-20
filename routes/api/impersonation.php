<?php

use App\Http\Controllers\Admin\ImpersonationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Impersonación de soporte (docs/impersonation.md)
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

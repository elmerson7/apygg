<?php

use App\Http\Controllers\ExportImport\ExportController;
use App\Http\Controllers\ExportImport\ImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Exportar e importar CSV/XLSX (docs/export-import.md)
|--------------------------------------------------------------------------
|
| Cada usuario solo ve SUS exportaciones/importaciones del tenant activo. El exportador/importador
| de cada ruta va fijado en `defaults` (la clave nunca llega del cliente).
|
*/

// Plataforma (sin tenant activo)
Route::middleware(['auth:api'])->prefix('admin')->group(function () {
    Route::middleware('permission:users.export,api')->prefix('exports')->group(function () {
        Route::post('/users', [ExportController::class, 'store'])
            ->defaults('exporter', 'users')->middleware('idempotent')->name('admin.exports.users');
        Route::get('/', [ExportController::class, 'index'])->name('admin.exports.index');
        Route::get('/{id}', [ExportController::class, 'show'])->whereNumber('id')->name('admin.exports.show');
        Route::get('/{id}/download', [ExportController::class, 'download'])->whereNumber('id')->name('admin.exports.download');
    });

    Route::middleware('permission:users.import,api')->prefix('imports')->group(function () {
        Route::post('/users', [ImportController::class, 'store'])
            ->defaults('importer', 'users')->middleware('idempotent')->name('admin.imports.users');
        Route::get('/', [ImportController::class, 'index'])->name('admin.imports.index');
        Route::get('/{id}', [ImportController::class, 'show'])->whereNumber('id')->name('admin.imports.show');
        Route::post('/{id}/confirm', [ImportController::class, 'confirm'])->whereNumber('id')->middleware('idempotent')->name('admin.imports.confirm');
        Route::get('/{id}/errors', [ImportController::class, 'errors'])->whereNumber('id')->name('admin.imports.errors');
    });
});

// Dentro de un tenant
Route::middleware(['auth:api', 'tenancy.enabled', 'tenant', 'permission:tenant-members.export,api'])->prefix('tenant/exports')->group(function () {
    Route::post('/members', [ExportController::class, 'store'])
        ->defaults('exporter', 'tenant-members')->middleware('idempotent')->name('tenant.exports.members');
    Route::get('/', [ExportController::class, 'index'])->name('tenant.exports.index');
    Route::get('/{id}', [ExportController::class, 'show'])->whereNumber('id')->name('tenant.exports.show');
    Route::get('/{id}/download', [ExportController::class, 'download'])->whereNumber('id')->name('tenant.exports.download');
});

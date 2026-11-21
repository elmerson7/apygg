<?php

use App\Http\Controllers\Sync\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sincronización offline (módulo `offline-sync`, docs/offline-sync.md)
|--------------------------------------------------------------------------
|
| Apagado por defecto: sin OFFLINE_SYNC_ENABLED no se registra ninguna ruta. Los permisos de cada recurso los
| aplica el servicio operación a operación (config/offline-sync.php), por eso aquí no hay `permission:`.
*/

if (config('offline-sync.enabled')) {
    Route::middleware(['auth:api', 'tenant'])->prefix('sync')->group(function () {
        Route::post('/batch', [SyncController::class, 'batch'])->name('sync.batch');
        Route::get('/{resource}', [SyncController::class, 'changes'])->name('sync.changes');
    });
}

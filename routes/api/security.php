<?php

use App\Http\Controllers\Security\BlacklistController;
use App\Http\Controllers\Security\SecurityScanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Security Routes
|--------------------------------------------------------------------------
|
| Lista de identidades bloqueadas (correo, documento o usuario). Distinta del
| bloqueo por IP de config/security.php, que actúa sobre la conexión.
|
*/

Route::middleware(['auth:api', 'permission:security.blacklist'])
    ->prefix('admin/blacklist')
    ->group(function () {
        Route::get('/', [BlacklistController::class, 'index'])->name('blacklist.index');
        Route::post('/', [BlacklistController::class, 'store'])->name('blacklist.store');
        Route::get('/check', [BlacklistController::class, 'check'])->name('blacklist.check');
        Route::delete('/{blacklist}', [BlacklistController::class, 'destroy'])->name('blacklist.destroy');
    });

Route::middleware(['auth:api', 'permission:security.audit'])
    ->prefix('admin/security/scans')
    ->group(function () {
        Route::get('/', [SecurityScanController::class, 'index'])->name('security.scans.index');
        Route::post('/', [SecurityScanController::class, 'store'])->name('security.scans.store');
        Route::get('/preview', [SecurityScanController::class, 'preview'])->name('security.scans.preview');
        Route::get('/{securityScan}', [SecurityScanController::class, 'show'])->name('security.scans.show');
        Route::post('/{securityScan}/stop', [SecurityScanController::class, 'stop'])->name('security.scans.stop');
    });

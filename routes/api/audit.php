<?php

use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Tenant\TenantAuditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auditoría de negocio (docs/audit.md)
|--------------------------------------------------------------------------
|
| /tenant/audit: SOLO la del tenant activo (middleware `tenant`); el servicio vuelve a exigir
|   `tenant-audit.read` al rol del actor en ese tenant.
| /admin/audit: toda la plataforma (`audit.read`).
*/
Route::middleware(['auth:api', 'tenancy.enabled', 'tenant', 'permission:tenant-audit.read,api'])->prefix('tenant/audit')->group(function () {
    Route::get('/', [TenantAuditController::class, 'index'])->name('tenant.audit.index');
    Route::get('/export', [TenantAuditController::class, 'export'])->name('tenant.audit.export');
});

Route::middleware(['auth:api', 'permission:audit.read,api'])->prefix('admin/audit')->group(function () {
    Route::get('/', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/export', [AuditController::class, 'export'])->name('audit.export');
});

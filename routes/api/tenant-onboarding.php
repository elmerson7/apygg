<?php

use App\Http\Controllers\Tenancy\TenantController;
use App\Http\Controllers\Tenancy\TenantInvitationController;
use App\Http\Controllers\Tenancy\TenantMemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Alta de empresas, invitaciones y miembros del tenant (docs/tenant-onboarding.md)
|--------------------------------------------------------------------------
|
| Solo funcionan con MULTI_TENANCY_ENABLED=true (`tenancy.enabled`: apagado responde 404).
| El tenant de las rutas con `tenant` sale de la cabecera/subdominio, nunca del cuerpo.
|
*/

// Sin tenant activo: el usuario aún no es miembro (alta) o va a serlo (aceptar invitación).
Route::middleware(['auth:api', 'tenancy.enabled'])->group(function () {
    Route::post('/tenants', [TenantController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('tenants.store');

    Route::get('/tenants/mine', [TenantController::class, 'mine'])
        ->name('tenants.mine');

    Route::post('/tenant/invitations/accept', [TenantInvitationController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('tenant.invitations.accept');
});

// Dentro del tenant activo.
Route::middleware(['auth:api', 'tenancy.enabled', 'tenant'])->prefix('tenant')->group(function () {
    Route::get('/members', [TenantMemberController::class, 'index'])
        ->middleware('permission:tenant-members.read,api')
        ->name('tenant.members.index');

    Route::patch('/members/{userId}', [TenantMemberController::class, 'update'])
        ->whereNumber('userId')
        ->middleware('permission:tenant-members.manage,api')
        ->name('tenant.members.update');

    Route::post('/members/{userId}/suspend', [TenantMemberController::class, 'suspend'])
        ->whereNumber('userId')
        ->middleware('permission:tenant-members.manage,api')
        ->name('tenant.members.suspend');

    Route::post('/members/{userId}/reactivate', [TenantMemberController::class, 'reactivate'])
        ->whereNumber('userId')
        ->middleware('permission:tenant-members.manage,api')
        ->name('tenant.members.reactivate');

    Route::delete('/members/{userId}', [TenantMemberController::class, 'destroy'])
        ->whereNumber('userId')
        ->middleware('permission:tenant-members.manage,api')
        ->name('tenant.members.destroy');

    Route::middleware('permission:tenant-invitations.manage,api')->group(function () {
        Route::get('/invitations', [TenantInvitationController::class, 'index'])->name('tenant.invitations.index');

        Route::post('/invitations', [TenantInvitationController::class, 'store'])
            ->middleware(['throttle:30,1', 'idempotent'])
            ->name('tenant.invitations.store');

        Route::post('/invitations/{id}/resend', [TenantInvitationController::class, 'resend'])
            ->whereNumber('id')
            ->middleware('throttle:30,1')
            ->name('tenant.invitations.resend');

        Route::delete('/invitations/{id}', [TenantInvitationController::class, 'destroy'])
            ->whereNumber('id')
            ->name('tenant.invitations.destroy');
    });
});

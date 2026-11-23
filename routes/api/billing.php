<?php

use App\Http\Controllers\Billing\AdminBillingController;
use App\Http\Controllers\Billing\PlanController;
use App\Http\Controllers\Billing\TenantInvoiceController;
use App\Http\Controllers\Billing\TenantPaymentMethodController;
use App\Http\Controllers\Billing\TenantSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facturación y suscripciones (docs/billing.md)
|--------------------------------------------------------------------------
|
| Público: el catálogo. Dentro del tenant (X-Tenant, solo con MULTI_TENANCY_ENABLED=true): suscripción, método de
| pago y facturas; cada servicio vuelve a comprobar el rol del actor EN ese tenant (el `permission:` no basta con
| equipos de Spatie apagados). Plataforma (`billing.manage`): pago manual, periodo gratis y asignar planes (también privados), con motivo y auditados.
|
*/

Route::get('/plans', [PlanController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('billing.plans.index');

Route::middleware(['auth:api', 'tenancy.enabled', 'tenant'])->prefix('tenant/billing')->group(function () {
    Route::get('/subscription', [TenantSubscriptionController::class, 'show'])
        ->middleware('permission:tenant-billing.read,api')
        ->name('tenant.billing.subscription.show');

    Route::post('/subscription', [TenantSubscriptionController::class, 'store'])
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1', 'idempotent:required'])
        ->name('tenant.billing.subscription.store');

    Route::post('/subscription/preview-change', [TenantSubscriptionController::class, 'preview'])
        ->middleware('permission:tenant-billing.read,api')
        ->name('tenant.billing.subscription.preview');

    Route::post('/subscription/change', [TenantSubscriptionController::class, 'change'])
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1', 'idempotent:required'])
        ->name('tenant.billing.subscription.change');

    Route::post('/subscription/cancel', [TenantSubscriptionController::class, 'cancel'])
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1'])
        ->name('tenant.billing.subscription.cancel');

    Route::post('/subscription/resume', [TenantSubscriptionController::class, 'resume'])
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1'])
        ->name('tenant.billing.subscription.resume');

    Route::get('/payment-method', [TenantPaymentMethodController::class, 'show'])
        ->middleware('permission:tenant-billing.read,api')
        ->name('tenant.billing.payment-method.show');

    Route::put('/payment-method', [TenantPaymentMethodController::class, 'update'])
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1'])
        ->name('tenant.billing.payment-method.update');

    Route::get('/invoices', [TenantInvoiceController::class, 'index'])
        ->middleware('permission:tenant-billing.read,api')
        ->name('tenant.billing.invoices.index');

    Route::get('/invoices/{id}', [TenantInvoiceController::class, 'show'])
        ->whereNumber('id')
        ->middleware('permission:tenant-billing.read,api')
        ->name('tenant.billing.invoices.show');

    Route::get('/invoices/{id}/pdf', [TenantInvoiceController::class, 'pdf'])
        ->whereNumber('id')
        ->middleware(['permission:tenant-billing.read,api', 'throttle:30,1'])
        ->name('tenant.billing.invoices.pdf');

    Route::post('/invoices/{id}/pay', [TenantInvoiceController::class, 'pay'])
        ->whereNumber('id')
        ->middleware(['permission:tenant-billing.manage,api', 'throttle:10,1', 'idempotent:required'])
        ->name('tenant.billing.invoices.pay');
});

Route::middleware(['auth:api', 'permission:billing.manage,api'])->prefix('admin/billing')->group(function () {
    Route::post('/invoices/{id}/mark-paid', [AdminBillingController::class, 'markPaid'])
        ->whereNumber('id')
        ->middleware('idempotent:required')->name('admin.billing.invoices.mark-paid');

    Route::post('/tenants/{tenant}/plan', [AdminBillingController::class, 'assignPlan'])
        ->whereNumber('tenant')
        ->middleware('idempotent:required')->name('admin.billing.plan.assign');

    Route::get('/discrepancies', [AdminBillingController::class, 'discrepancies'])
        ->name('admin.billing.discrepancies.index');

    Route::post('/discrepancies/{id}/resolve', [AdminBillingController::class, 'resolveDiscrepancy'])
        ->whereNumber('id')
        ->middleware('idempotent')->name('admin.billing.discrepancies.resolve');

    Route::post('/tenants/{tenant}/free-period', [AdminBillingController::class, 'grantFreePeriod'])
        ->whereNumber('tenant')
        ->middleware('idempotent:required')->name('admin.billing.free-period');
});

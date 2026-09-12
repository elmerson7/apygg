<?php

use App\Http\Controllers\Mail\MailAccountController;
use App\Http\Controllers\Mail\MailLogController;
use App\Http\Controllers\Mail\MailProviderController;
use App\Http\Controllers\Mail\MailSettingsController;
use App\Http\Controllers\Mail\MailTemplateController;
use App\Http\Controllers\Webhooks\MailWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mail System Routes
|--------------------------------------------------------------------------
|
| Administración del sistema de correo: proveedores, cuentas de envío,
| plantillas, ajustes globales e historial de envíos.
|
| Todas las rutas de administración requieren JWT + el permiso 'mail.manage',
| salvo el historial, que se conforma con 'mail.logs' (solo lectura).
|
| El endpoint de webhook es público por necesidad (lo llama el proveedor) y se
| protege verificando la firma del payload, no con autenticación.
|
*/

Route::middleware(['auth:api', 'permission:mail.manage'])
    ->prefix('admin/mail')
    ->group(function () {
        // Proveedores
        Route::get('/providers', [MailProviderController::class, 'index'])->name('mail.providers.index');
        Route::post('/providers', [MailProviderController::class, 'store'])->name('mail.providers.store');
        Route::get('/providers/{mailProvider}', [MailProviderController::class, 'show'])->name('mail.providers.show');
        Route::put('/providers/{mailProvider}', [MailProviderController::class, 'update'])->name('mail.providers.update');
        Route::delete('/providers/{mailProvider}', [MailProviderController::class, 'destroy'])->name('mail.providers.destroy');

        // Cuentas de envío
        Route::get('/accounts', [MailAccountController::class, 'index'])->name('mail.accounts.index');
        Route::post('/accounts', [MailAccountController::class, 'store'])->name('mail.accounts.store');
        Route::get('/accounts/{mailAccount}', [MailAccountController::class, 'show'])->name('mail.accounts.show');
        Route::put('/accounts/{mailAccount}', [MailAccountController::class, 'update'])->name('mail.accounts.update');
        Route::delete('/accounts/{mailAccount}', [MailAccountController::class, 'destroy'])->name('mail.accounts.destroy');
        Route::post('/accounts/{mailAccount}/test', [MailAccountController::class, 'test'])->name('mail.accounts.test');

        // Plantillas
        Route::get('/templates', [MailTemplateController::class, 'index'])->name('mail.templates.index');
        Route::post('/templates/seed-defaults', [MailTemplateController::class, 'seedDefaults'])->name('mail.templates.seed');
        Route::get('/templates/{mailTemplate}', [MailTemplateController::class, 'show'])->name('mail.templates.show');
        Route::put('/templates/{mailTemplate}', [MailTemplateController::class, 'update'])->name('mail.templates.update');
        Route::post('/templates/{mailTemplate}/toggle', [MailTemplateController::class, 'toggle'])->name('mail.templates.toggle');

        // Ajustes globales (pausa total, límites)
        Route::get('/settings', [MailSettingsController::class, 'index'])->name('mail.settings.index');
        Route::put('/settings', [MailSettingsController::class, 'update'])->name('mail.settings.update');
    });

// Historial de envíos: permiso propio, de solo lectura
Route::middleware(['auth:api', 'permission:mail.logs'])
    ->prefix('admin/mail')
    ->group(function () {
        Route::get('/logs', [MailLogController::class, 'index'])->name('mail.logs.index');
        Route::get('/logs/stats', [MailLogController::class, 'stats'])->name('mail.logs.stats');
        Route::get('/logs/{mailLog}', [MailLogController::class, 'show'])->name('mail.logs.show');
    });

// Webhook del proveedor: autenticado por firma (ver SvixSignatureVerifier)
Route::post('/webhooks/mail', [MailWebhookController::class, 'handle'])
    ->name('webhooks.mail');

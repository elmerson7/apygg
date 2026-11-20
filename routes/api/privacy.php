<?php

use App\Http\Controllers\Privacy\AccountDeletionController;
use App\Http\Controllers\Privacy\ConsentController;
use App\Http\Controllers\Privacy\LegalDocumentController;
use App\Http\Controllers\Privacy\PersonalDataExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Privacidad (docs/privacy.md)
|--------------------------------------------------------------------------
|
| - `/legal`: versión vigente de los documentos legales (público).
| - `/user/consents`, `/user/data-export`, `/user/account-deletion`: derechos del usuario en sesión, sin permiso
|   propio (cada uno toca solo lo suyo). Son de la persona: sin middleware `tenant`.
| - `/admin/legal-documents`: publicar y ver versiones (permisos `legal-documents.*`).
|
*/

Route::get('legal', [LegalDocumentController::class, 'current'])->name('legal.index');
Route::get('legal/{key}', [LegalDocumentController::class, 'show'])->name('legal.show');

// Enlace del aviso: autenticado por la firma temporal, no por el JWT (se abre desde el correo).
Route::get('user/data-export/{id}/download', [PersonalDataExportController::class, 'download'])
    ->middleware(['signed', 'throttle:30,1'])->whereNumber('id')->name('privacy.data-export.download');

Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('consents', [ConsentController::class, 'index'])->name('user.consents.index');
    Route::post('consents', [ConsentController::class, 'accept'])->name('user.consents.accept');
    Route::delete('consents/{key}', [ConsentController::class, 'withdraw'])->name('user.consents.withdraw');

    Route::get('data-export', [PersonalDataExportController::class, 'index'])->name('user.data-export.index');
    Route::post('data-export', [PersonalDataExportController::class, 'store'])->middleware('throttle:5,60')->name('user.data-export.store');
    Route::get('data-export/{id}/link', [PersonalDataExportController::class, 'link'])->whereNumber('id')->name('user.data-export.link');

    Route::get('account-deletion', [AccountDeletionController::class, 'show'])->name('user.account-deletion.show');
    Route::post('account-deletion', [AccountDeletionController::class, 'store'])->middleware('throttle:5,60')->name('user.account-deletion.store');
    Route::delete('account-deletion', [AccountDeletionController::class, 'destroy'])->name('user.account-deletion.destroy');
});

Route::middleware(['auth:api'])->prefix('admin/legal-documents')->group(function () {
    Route::get('/', [LegalDocumentController::class, 'index'])->middleware('permission:legal-documents.read,api')->name('admin.legal-documents.index');
    Route::post('/', [LegalDocumentController::class, 'store'])->middleware('permission:legal-documents.manage,api')->name('admin.legal-documents.store');
});

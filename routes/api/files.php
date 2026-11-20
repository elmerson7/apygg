<?php

use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Files\FileUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| File Routes
|--------------------------------------------------------------------------
|
| Rutas relacionadas con gestión de archivos.
| Todas las rutas aquí requieren autenticación JWT.
|
*/

// Descarga con URL firmada y temporal (archivos privados). Sin JWT: la firma es la credencial.
Route::get('/files/{id}/signed', [FileController::class, 'signedDownload'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('files.signed');

Route::middleware(['auth:api', 'tenant'])->prefix('files')->group(function () {
    // Listar archivos
    Route::get('/', [FileController::class, 'index'])
        ->name('files.index');

    // Subir archivo
    Route::post('/', [FileController::class, 'store'])
        ->name('files.store');

    // Uso y límites de almacenamiento del tenant (antes de /{id})
    Route::get('/usage', [FileController::class, 'usage'])
        ->name('files.usage');

    // Subida directa presignada: intención (reserva cuota + URL PUT) y verificación al completar
    Route::post('/uploads', [FileUploadController::class, 'store'])
        ->middleware('idempotent')
        ->name('files.uploads.store');
    Route::post('/uploads/{uuid}/complete', [FileUploadController::class, 'complete'])
        ->whereUuid('uuid')
        ->name('files.uploads.complete');

    // Obtener archivo específico
    Route::get('/{id}', [FileController::class, 'show'])
        ->name('files.show');

    // Actualizar metadatos de archivo
    Route::put('/{id}', [FileController::class, 'update'])
        ->name('files.update');

    // Eliminar archivo
    Route::delete('/{id}', [FileController::class, 'destroy'])
        ->name('files.destroy');

    // Descargar archivo
    Route::get('/{id}/download', [FileController::class, 'download'])
        ->name('files.download');

    // URL temporal firmada para descargar sin cabecera Authorization
    Route::get('/{id}/temporary-url', [FileController::class, 'temporaryUrl'])
        ->name('files.temporary-url');
});

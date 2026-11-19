<?php

use App\Http\Controllers\Files\FileController;
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

Route::middleware(['auth:api'])->prefix('files')->group(function () {
    // Listar archivos
    Route::get('/', [FileController::class, 'index'])
        ->name('files.index');

    // Subir archivo
    Route::post('/', [FileController::class, 'store'])
        ->name('files.store');

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

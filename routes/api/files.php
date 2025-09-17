<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Files\FileController;

Route::middleware(['jwt', 'throttle:files'])->group(function () {
    
    // Upload endpoints
    Route::post('/upload/generate-url', [FileController::class, 'generateUploadUrl'])
        ->name('files.upload.generate-url');
    
    Route::post('/upload/confirm', [FileController::class, 'confirmUpload'])
        ->name('files.upload.confirm');
    
    Route::post('/upload/direct', [FileController::class, 'uploadDirect'])
        ->name('files.upload.direct');
    
    // File management
    Route::get('/', [FileController::class, 'index'])
        ->name('files.index');
    
    Route::get('/search', [FileController::class, 'search'])
        ->name('files.search');
    
    Route::get('/recent', [FileController::class, 'recent'])
        ->name('files.recent');
    
    Route::get('/stats', [FileController::class, 'stats'])
        ->name('files.stats');
    
    Route::get('/{file}', [FileController::class, 'show'])
        ->name('files.show');
    
    Route::delete('/{file}', [FileController::class, 'destroy'])
        ->name('files.destroy');
    
    // Download endpoint
    Route::get('/{file}/download', [FileController::class, 'generateDownloadUrl'])
        ->name('files.download');
});

// Public access endpoint (no auth required)
Route::get('/public/{file}', [FileController::class, 'showPublic'])
    ->middleware(['throttle:public-files'])
    ->name('files.public');

<?php

use App\Http\Controllers\Logs\ApiErrorStatsController;
use Illuminate\Support\Facades\Route;

// Rutas para estadísticas de errores de API
// Protegidas con autenticación JWT y posiblemente con permisos de admin
Route::middleware(['jwt'])->prefix('logs')->group(function () {
    
    // Estadísticas generales de errores
    Route::get('api-errors', [ApiErrorStatsController::class, 'index'])
        ->name('logs.api-errors.index');
    
    // Endpoints más problemáticos
    Route::get('api-errors/problematic-endpoints', [ApiErrorStatsController::class, 'problematicEndpoints'])
        ->name('logs.api-errors.problematic');
    
    // Tendencias de errores por hora
    Route::get('api-errors/trends', [ApiErrorStatsController::class, 'trends'])
        ->name('logs.api-errors.trends');
    
    // Health check de errores de API
    Route::get('api-errors/health', [ApiErrorStatsController::class, 'health'])
        ->name('logs.api-errors.health');
    
    // Errores de un usuario específico
    Route::get('api-errors/users/{userId}', [ApiErrorStatsController::class, 'userErrors'])
        ->name('logs.api-errors.user');
    
    // Errores por trace ID (para debugging)
    Route::get('api-errors/trace/{traceId}', [ApiErrorStatsController::class, 'traceErrors'])
        ->name('logs.api-errors.trace');
    
});

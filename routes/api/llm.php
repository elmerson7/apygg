<?php

use App\Http\Controllers\Llm\LlmAreaController;
use App\Http\Controllers\Llm\LlmAuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LLM Routes
|--------------------------------------------------------------------------
|
| Administración de la capa de IA: áreas (qué modelo usa cada una) y auditoría
| de consumo.
|
| Gestión: permiso 'llm.manage'. Auditoría: permiso 'llm.logs' (solo lectura).
|
*/

Route::middleware(['auth:api', 'permission:llm.manage,api'])
    ->prefix('admin/llm')
    ->group(function () {
        Route::get('/status', [LlmAreaController::class, 'status'])->name('llm.status');

        Route::get('/areas', [LlmAreaController::class, 'index'])->name('llm.areas.index');
        Route::post('/areas', [LlmAreaController::class, 'store'])->name('llm.areas.store');
        Route::get('/areas/{llmArea}', [LlmAreaController::class, 'show'])->name('llm.areas.show');
        Route::put('/areas/{llmArea}', [LlmAreaController::class, 'update'])->name('llm.areas.update');
        Route::delete('/areas/{llmArea}', [LlmAreaController::class, 'destroy'])->name('llm.areas.destroy');
        Route::post('/areas/{llmArea}/test', [LlmAreaController::class, 'test'])->name('llm.areas.test');
    });

Route::middleware(['auth:api', 'permission:llm.logs,api'])
    ->prefix('admin/llm')
    ->group(function () {
        Route::get('/logs', [LlmAuditLogController::class, 'index'])->name('llm.logs.index');
        Route::get('/logs/stats', [LlmAuditLogController::class, 'stats'])->name('llm.logs.stats');
        Route::get('/logs/{llmLog}', [LlmAuditLogController::class, 'show'])->name('llm.logs.show');
    });

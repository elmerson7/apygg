<?php

use App\Http\Controllers\Docs\DocsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Docs Assistant Routes
|--------------------------------------------------------------------------
|
| Búsqueda y consulta en lenguaje natural sobre la documentación indexada
| (`php artisan docs:index`).
|
| Requieren autenticación: la documentación técnica de un proyecto no suele
| ser pública, y las preguntas consumen cuota de IA.
|
*/

Route::middleware('auth:api')->prefix('docs')->group(function () {
    Route::get('/', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/search', [DocsController::class, 'search'])->name('docs.search');
    Route::post('/ask', [DocsController::class, 'ask'])->middleware('idempotent')->name('docs.ask');
    Route::get('/conversations', [DocsController::class, 'conversations'])->name('docs.conversations');
    Route::get('/conversations/{docConversation}', [DocsController::class, 'conversation'])->name('docs.conversation');
});

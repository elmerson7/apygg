<?php

use App\Http\Controllers\Search\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Search Routes
|--------------------------------------------------------------------------
|
| Rutas para búsqueda global en múltiples modelos usando Meilisearch.
| Ruta: GET /search (sin prefijo /api según reglas del proyecto)
| Requiere autenticación JWT.
|
*/

Route::middleware(['auth:api'])->group(function () {
    Route::get('/search', [SearchController::class, 'search'])->name('search');
});

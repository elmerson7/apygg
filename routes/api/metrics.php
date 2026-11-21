<?php

use App\Http\Controllers\Metrics\MetricsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Métricas Prometheus (docs/metrics.md)
|--------------------------------------------------------------------------
| Fuera del prefijo de versión (lo consulta Prometheus, no las apps) y protegida por token o IP.
*/
Route::get('metrics', [MetricsController::class, 'show'])->middleware('metrics.auth')->name('metrics.show');

<?php

namespace App\Http\Controllers\Metrics;

use App\Contracts\MetricsServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    /**
     * Métricas en formato de texto de Prometheus. No usa el envelope JSON: Prometheus exige texto plano.
     */
    public function show(MetricsServiceInterface $metrics): Response
    {
        return response($metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}

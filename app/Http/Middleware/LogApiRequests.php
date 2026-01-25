<?php

namespace App\Http\Middleware;

use App\Infrastructure\Logging\Loggers\ApiLogger;
use App\Infrastructure\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LogApiRequests Middleware
 *
 * Middleware para registrar automáticamente todos los requests y responses de la API.
 *
 * @package App\Http\Middleware
 */
class LogApiRequests
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Establecer trace ID si no existe
        if (!$request->header('X-Trace-ID')) {
            $traceId = LogService::getTraceId();
            $request->headers->set('X-Trace-ID', $traceId);
        }

        // Medir tiempo de ejecución
        $startTime = microtime(true);

        // Procesar request
        $response = $next($request);

        // Calcular tiempo de respuesta en milisegundos
        $responseTime = (microtime(true) - $startTime) * 1000;

        // Registrar request/response (no bloquea si falla)
        try {
            ApiLogger::logRequest($request, $response, $responseTime);
        } catch (\Exception $e) {
            // Silenciar errores de logging para no interrumpir el flujo principal
            \Log::warning('Failed to log API request', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}

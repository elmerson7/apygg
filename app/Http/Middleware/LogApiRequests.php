<?php

namespace App\Http\Middleware;

use App\Infrastructure\Logging\Loggers\ApiLogger;
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
        // El trace ID ya está establecido por TraceIdMiddleware
        // Solo necesitamos medir el tiempo de ejecución

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

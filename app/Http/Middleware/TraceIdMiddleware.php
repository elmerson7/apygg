<?php

namespace App\Http\Middleware;

use App\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * TraceIdMiddleware
 *
 * Middleware para generar e inyectar un UUID único (Trace ID) en cada request.
 * Permite rastrear un request completo a través de toda la aplicación.
 */
class TraceIdMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener trace ID del header si existe, o generar uno nuevo
        $traceId = $request->header('X-Trace-ID')
            ?? $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        // Establecer trace ID en el request
        $request->headers->set('X-Trace-ID', $traceId);

        // Sincronizar con LogService para que esté disponible en toda la aplicación
        LogService::setTraceId($traceId);

        // Establecer trace ID en el contexto de logging
        Log::withContext(['trace_id' => $traceId]);

        // Procesar request
        $response = $next($request);

        // Inyectar trace ID en la respuesta
        $response->headers->set('X-Trace-ID', $traceId);

        return $response;
    }
}

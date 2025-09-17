<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceId
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generar o usar trace_id existente del header
        $traceId = $request->header('X-Request-Id')
                ?? $request->header('X-Trace-Id')
                ?? Str::ulid()->toString();

        // Almacenar en request para uso posterior
        $request->attributes->set('trace_id', $traceId);

        $response = $next($request);

        // Agregar ambos headers en respuesta para compatibilidad y debugging
        $response->headers->set('X-Request-Id', $traceId);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Infrastructure\Logging\Loggers\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LogSecurityEvents Middleware
 *
 * Middleware para registrar eventos de seguridad (403, 401, etc.).
 *
 * @package App\Http\Middleware
 */
class LogSecurityEvents
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
        $response = $next($request);

        // Registrar eventos de seguridad según el código de respuesta
        try {
            $statusCode = $response->getStatusCode();

            // 401 - No autenticado
            if ($statusCode === 401) {
                SecurityLogger::logPermissionDenied(
                    auth()->user(),
                    'unauthorized',
                    $request->path(),
                    [
                        'method' => $request->method(),
                        'ip' => $request->ip(),
                    ]
                );
            }

            // 403 - No autorizado
            if ($statusCode === 403) {
                SecurityLogger::logPermissionDenied(
                    auth()->user(),
                    'forbidden',
                    $request->path(),
                    [
                        'method' => $request->method(),
                        'ip' => $request->ip(),
                    ]
                );
            }
        } catch (\Exception $e) {
            // Silenciar errores de logging para no interrumpir el flujo principal
            \Log::warning('Failed to log security event', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para forzar respuestas JSON en API
 * 
 * Asegura que todas las respuestas de la API sean en formato JSON,
 * independientemente del Accept header del cliente.
 */
class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Forzar Accept header a application/json
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        // Asegurar que la respuesta sea JSON si no lo es ya
        if (!$response->headers->has('Content-Type') || 
            !str_contains($response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}

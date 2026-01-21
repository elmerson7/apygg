<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener configuración de CORS
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedMethods = config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        $allowedHeaders = config('cors.allowed_headers', []);
        $exposedHeaders = config('cors.exposed_headers', []);
        $maxAge = config('cors.max_age', 3600);
        $supportsCredentials = config('cors.supports_credentials', false);

        // Obtener el origen de la petición
        $origin = $request->headers->get('Origin');

        // Verificar si el origen está permitido
        $isOriginAllowed = $this->isOriginAllowed($origin, $allowedOrigins);

        // Manejar preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        // Aplicar headers CORS solo si el origen está permitido
        if ($isOriginAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } elseif (in_array('*', $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        // Headers adicionales
        if ($isOriginAllowed || in_array('*', $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
            $response->headers->set('Access-Control-Max-Age', (string) $maxAge);

            if ($supportsCredentials) {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }

    /**
     * Verificar si el origen está permitido.
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Si hay wildcard, permitir todos
        if (in_array('*', $allowedOrigins)) {
            return true;
        }

        // Verificar coincidencia exacta
        if (in_array($origin, $allowedOrigins)) {
            return true;
        }

        // Verificar patrones con wildcard (ej: https://*.example.com)
        foreach ($allowedOrigins as $allowedOrigin) {
            if ($this->matchesPattern($origin, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si el origen coincide con un patrón permitido.
     */
    private function matchesPattern(string $origin, string $pattern): bool
    {
        // Convertir patrón a regex
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = '/^' . $pattern . '$/';

        return (bool) preg_match($pattern, $origin);
    }
}

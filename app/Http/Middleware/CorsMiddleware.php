<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware personalizado para manejar CORS
 * 
 * Controla qué orígenes, métodos y headers están permitidos según el entorno.
 * Usa ALLOWED_ORIGINS en todos los entornos (dev, staging, prod) para garantizar
 * que la configuración funcione correctamente.
 */
class CorsMiddleware
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
        $allowedMethods = config('cors.allowed_methods', []);
        $allowedHeaders = config('cors.allowed_headers', []);
        $exposedHeaders = config('cors.exposed_headers', []);
        $maxAge = config('cors.max_age', 3600);
        $supportsCredentials = config('cors.supports_credentials', true);

        // Obtener origen de la petición
        $origin = $request->headers->get('Origin');

        // Manejar preflight request (OPTIONS)
        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflight(
                $origin,
                $allowedOrigins,
                $allowedMethods,
                $allowedHeaders,
                $maxAge,
                $supportsCredentials
            );
        }

        // Procesar request normal
        $response = $next($request);

        // Agregar headers CORS a la respuesta
        return $this->addCorsHeaders(
            $response,
            $origin,
            $allowedOrigins,
            $allowedHeaders,
            $exposedHeaders,
            $supportsCredentials
        );
    }

    /**
     * Manejar preflight request (OPTIONS)
     */
    private function handlePreflight(
        ?string $origin,
        array $allowedOrigins,
        array $allowedMethods,
        array $allowedHeaders,
        int $maxAge,
        bool $supportsCredentials
    ): Response {
        $response = response('', 204);

        // Verificar si el origen está permitido
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        if ($supportsCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));
        $response->headers->set('Access-Control-Max-Age', (string) $maxAge);

        return $response;
    }

    /**
     * Agregar headers CORS a la respuesta
     */
    private function addCorsHeaders(
        Response $response,
        ?string $origin,
        array $allowedOrigins,
        array $allowedHeaders,
        array $exposedHeaders,
        bool $supportsCredentials
    ): Response {
        // Verificar si el origen está permitido
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        if ($supportsCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($exposedHeaders)) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }

        return $response;
    }

    /**
     * Verificar si el origen está permitido
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Normalizar origen (remover trailing slash)
        $origin = rtrim($origin, '/');

        // Verificar si está en la lista de permitidos
        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = rtrim($allowedOrigin, '/');
            
            // Comparación exacta
            if ($origin === $allowedOrigin) {
                return true;
            }

            // Soporte para wildcard en subdominios (ej: *.example.com)
            if (str_starts_with($allowedOrigin, '*.')) {
                $domain = substr($allowedOrigin, 2);
                if (str_ends_with($origin, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}

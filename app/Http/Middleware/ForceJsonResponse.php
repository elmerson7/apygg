<?php

namespace App\Http\Middleware;

use App\Support\ApiPath;
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
     * Rutas que NO deben forzar JSON
     */
    /** @var list<string> */
    private array $excludedPaths = [
        '/telescope',
        '/horizon',
        '/up',
        '/metrics', // module:metrics
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // No forzar JSON para rutas excluidas
        if ($this->shouldExclude($request)) {
            return $next($request);
        }

        // Forzar Accept header a application/json
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        // Asegurar que la respuesta sea JSON si no lo es ya
        // Las descargas (CSV en streaming, archivos) conservan su Content-Type.
        if (str_starts_with((string) $response->headers->get('Content-Disposition'), 'attachment')) {
            return $response;
        }

        if (! $response->headers->has('Content-Type') ||
            ! str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }

    /**
     * Verificar si la ruta debe ser excluida
     */
    private function shouldExclude(Request $request): bool
    {
        $path = ApiPath::path($request);

        foreach ($this->excludedPaths as $excludedPath) {
            if (str_starts_with($path, ltrim($excludedPath, '/'))) {
                return true;
            }
        }

        return false;
    }
}

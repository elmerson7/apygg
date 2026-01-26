<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CompressionMiddleware
 *
 * Middleware para habilitar compresión HTTP (gzip) en respuestas.
 * Útil cuando FrankenPHP no está usando Caddy o cuando se necesita compresión
 * a nivel de aplicación.
 */
class CompressionMiddleware
{
    /**
     * Tipos MIME que deben comprimirse
     */
    private array $compressibleTypes = [
        'application/json',
        'application/javascript',
        'application/xml',
        'text/html',
        'text/css',
        'text/javascript',
        'text/plain',
        'text/xml',
    ];

    /**
     * Tamaño mínimo de respuesta para comprimir (en bytes)
     * Nota: Para respuestas muy pequeñas (< 1KB), la compresión añade overhead
     * Health checks y respuestas pequeñas no se comprimen para mejor rendimiento
     */
    private int $minSize = 1024; // 1KB (valor recomendado para evitar overhead)

    /**
     * Rutas que NO deben comprimirse
     */
    private array $excludedPaths = [
        '/telescope',
        '/horizon',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // No comprimir si la ruta está excluida
        if ($this->shouldExclude($request)) {
            return $response;
        }

        // Verificar si el cliente acepta compresión
        $acceptEncoding = $request->header('Accept-Encoding', '');
        if (empty($acceptEncoding)) {
            return $response;
        }

        // Verificar si ya está comprimida
        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        // Verificar tipo de contenido
        $contentType = $response->headers->get('Content-Type', '');
        if (! $this->isCompressible($contentType)) {
            return $response;
        }

        // Verificar tamaño mínimo
        $content = $response->getContent();
        if (strlen($content) < $this->minSize) {
            return $response;
        }

        // Comprimir respuesta
        return $this->compressResponse($response, $acceptEncoding);
    }

    /**
     * Verificar si la ruta debe ser excluida
     */
    private function shouldExclude(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->excludedPaths as $excludedPath) {
            if (str_starts_with($path, ltrim($excludedPath, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si el tipo de contenido es comprimible
     */
    private function isCompressible(string $contentType): bool
    {
        foreach ($this->compressibleTypes as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprimir respuesta según el encoding aceptado
     */
    private function compressResponse(Response $response, string $acceptEncoding): Response
    {
        $content = $response->getContent();

        // Asegurar que el contenido sea string
        if (! is_string($content)) {
            return $response;
        }

        // Priorizar zstd si está disponible (mejor compresión)
        if (str_contains($acceptEncoding, 'zstd') && function_exists('zstd_compress')) {
            $compressed = @zstd_compress($content, 3); // Nivel 3 (balance entre velocidad y tamaño)
            if (is_string($compressed) && $compressed !== '') {
                $response->setContent($compressed);
                $response->headers->set('Content-Encoding', 'zstd');
                $response->headers->set('Vary', 'Accept-Encoding');
                // Actualizar Content-Length
                $contentLength = strlen($compressed);
                $response->headers->set('Content-Length', (string) $contentLength);

                return $response;
            }
        }

        // Fallback a gzip
        if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
            $compressed = @gzencode($content, 6); // Nivel 6 (balance)
            if (is_string($compressed) && $compressed !== '') {
                $response->setContent($compressed);
                $response->headers->set('Content-Encoding', 'gzip');
                $response->headers->set('Vary', 'Accept-Encoding');
                // Actualizar Content-Length
                $contentLength = strlen($compressed);
                $response->headers->set('Content-Length', (string) $contentLength);

                return $response;
            }
        }

        // Si no se pudo comprimir, devolver original
        return $response;
    }
}

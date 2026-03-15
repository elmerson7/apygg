<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ETagMiddleware
 *
 * Agrega soporte de ETag y Last-Modified a respuestas GET cacheables.
 * Devuelve 304 Not Modified cuando el cliente envía If-None-Match o If-Modified-Since.
 */
class ETagMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        /** @var SymfonyResponse $response */
        $response = $next($request);

        // Solo aplica a GET/HEAD con 200
        if (! in_array($request->method(), ['GET', 'HEAD'])) {
            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $content = $response->getContent();

        // Generar ETag
        $etag = '"' . md5($content) . '"';
        $response->setEtag($etag);

        // Last-Modified desde header existente o ahora
        if (! $response->headers->has('Last-Modified')) {
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        }

        // Cache-Control
        if (! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        }

        // Verificar If-None-Match
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304, [
                'ETag'          => $etag,
                'Last-Modified' => $response->headers->get('Last-Modified'),
                'Cache-Control' => $response->headers->get('Cache-Control'),
            ]);
        }

        // Verificar If-Modified-Since
        $ifModifiedSince = $request->header('If-Modified-Since');
        $lastModified    = $response->headers->get('Last-Modified');

        if ($ifModifiedSince && $lastModified) {
            $ifModifiedSinceTs = strtotime($ifModifiedSince);
            $lastModifiedTs    = strtotime($lastModified);

            if ($ifModifiedSinceTs >= $lastModifiedTs) {
                return response('', 304, [
                    'ETag'          => $etag,
                    'Last-Modified' => $lastModified,
                    'Cache-Control' => $response->headers->get('Cache-Control'),
                ]);
            }
        }

        return $response;
    }
}

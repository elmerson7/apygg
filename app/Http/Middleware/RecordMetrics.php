<?php

namespace App\Http\Middleware;

use App\Contracts\MetricsServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anota la petición en las métricas Prometheus (módulo `metrics`, apagado por defecto). Se escribe en
 * `terminate()`, después de enviar la respuesta, así que no suma latencia. Sin estado entre peticiones (Octane).
 */
class RecordMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('metrics.enabled')) {
            return;
        }

        $route = $request->route();
        $template = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : 'unmatched';

        if ($template === '/metrics' || str_ends_with($template, '/metrics')) {
            return;
        }

        $started = (float) $request->attributes->get('metrics_started', microtime(true));

        app(MetricsServiceInterface::class)->recordRequest(
            $request->getMethod(),
            $template,
            $response->getStatusCode(),
            microtime(true) - $started,
        );
    }
}

<?php

namespace App\Filament\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad del back-office.
 *
 * `SecurityHeadersMiddleware` (grupo api) lleva una CSP de API (`script-src 'none'`) que
 * rompería Filament, y no se aplica a este panel. Aquí van las cabeceras que sí encajan
 * con una app Livewire/Alpine. No se fija CSP: Alpine y Livewire necesitan evaluación
 * dinámica; si el proyecto la quiere, añádela aquí con nonces.
 */
class BackofficeSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        // Páginas con datos privados: que ningún proxy ni el historial las guarden.
        $response->headers->set('Cache-Control', 'no-store, private');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

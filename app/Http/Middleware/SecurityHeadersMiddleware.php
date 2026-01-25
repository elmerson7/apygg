<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeadersMiddleware
 *
 * Middleware para agregar headers de seguridad HTTP a todas las respuestas.
 * Protege contra clickjacking, MIME sniffing, XSS y otros ataques comunes.
 */
class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Agregar headers de seguridad
        $this->addSecurityHeaders($response, $request);

        return $response;
    }

    /**
     * Agregar headers de seguridad a la respuesta
     */
    protected function addSecurityHeaders(Response $response, Request $request): void
    {
        // X-Frame-Options: Previene clickjacking
        // DENY: No permite que la página se cargue en ningún iframe
        $response->headers->set('X-Frame-Options', 'DENY');

        // X-Content-Type-Options: Previene MIME sniffing
        // nosniff: El navegador no debe adivinar el tipo de contenido
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-XSS-Protection: Protección básica contra XSS (legacy, pero útil para navegadores antiguos)
        // 1; mode=block: Habilita protección XSS y bloquea la página si detecta un ataque
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Strict-Transport-Security (HSTS): Solo agregar si la conexión es HTTPS
        // Fuerza el uso de HTTPS por 1 año (31536000 segundos)
        if ($request->secure() || $request->header('X-Forwarded-Proto') === 'https') {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Content-Security-Policy: Política de seguridad de contenido
        // Previene XSS avanzado controlando qué recursos puede cargar la página
        // Para API, generalmente solo permitimos 'self'
        $csp = $this->buildContentSecurityPolicy();
        if ($csp) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // Referrer-Policy: Controla qué información del referrer se envía
        // strict-origin-when-cross-origin: Envía origen completo solo cuando es del mismo origen
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy (anteriormente Feature-Policy): Controla qué APIs del navegador están disponibles
        // Para API, generalmente restringimos características no necesarias
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=()'
        );
    }

    /**
     * Construir Content-Security-Policy
     */
    protected function buildContentSecurityPolicy(): ?string
    {
        // Para una API, generalmente solo necesitamos permitir 'self'
        // Si necesitas permitir otros orígenes, puedes configurarlo aquí
        $allowedOrigins = config('cors.allowed_origins', []);

        $directives = [
            "default-src 'self'",
            "script-src 'none'", // API no necesita scripts
            "style-src 'none'",  // API no necesita estilos
            "img-src 'none'",    // API no necesita imágenes
            "font-src 'none'",   // API no necesita fuentes
            "connect-src 'self'", // Solo permitir conexiones al mismo origen
            "frame-ancestors 'none'", // No permitir iframes (complementa X-Frame-Options)
        ];

        // Si hay orígenes permitidos en CORS, podemos agregarlos para connect-src si es necesario
        // Pero generalmente para API solo necesitamos 'self'

        return implode('; ', $directives);
    }
}

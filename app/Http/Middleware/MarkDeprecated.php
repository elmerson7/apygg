<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca una ruta como deprecada con las cabeceras estándar (docs/api-routes-conventions.md §Deprecar una ruta):
 *
 * - `Deprecation: true` (draft-ietf-httpapi-deprecation-header),
 * - `Sunset: <fecha HTTP>` (RFC 8594): cuándo dejará de funcionar,
 * - `Link: <url>; rel="deprecation"` (documentación de la migración), si se pasa.
 *
 * Uso: `->middleware('deprecated:2027-03-01')` o `deprecated:2027-03-01T00:00:00Z,https://docs.acme.test/migrar`.
 * No cambia el comportamiento de la ruta: solo avisa a los clientes. Una fecha ilegible no rompe la petición
 * (se omite `Sunset`; el test de rutas y la revisión de código la atrapan antes).
 */
class MarkDeprecated
{
    public function handle(Request $request, Closure $next, ?string $sunset = null, ?string $link = null): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');

        $date = $this->parse($sunset);

        if ($date !== null) {
            $response->headers->set('Sunset', $date->utc()->format('D, d M Y H:i:s').' GMT');
        }

        if ($link !== null && $link !== '') {
            $response->headers->set('Link', '<'.$link.'>; rel="deprecation"', false);
        }

        return $response;
    }

    private function parse(?string $sunset): ?CarbonImmutable
    {
        if ($sunset === null || trim($sunset) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($sunset), 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}

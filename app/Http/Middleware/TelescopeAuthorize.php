<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TelescopeAuthorize
 *
 * Middleware personalizado para autorizar acceso a Telescope.
 * Permite acceso solo en entornos de desarrollo sin requerir autenticación.
 */
class TelescopeAuthorize
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo permitir acceso en desarrollo
        if (! app()->environment(['local', 'dev'])) {
            abort(403, 'Telescope solo está disponible en desarrollo');
        }

        return $next($request);
    }
}

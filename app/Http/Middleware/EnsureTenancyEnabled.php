<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tenancy.enabled`: las rutas de alta de empresas, invitaciones y miembros solo existen con
 * `MULTI_TENANCY_ENABLED=true`. Apagado, responden 404 (como si no estuvieran registradas). Se evalúa en
 * la petición (no al registrar rutas) para que el estado sea el de la config actual, también en Octane y tests.
 */
class EnsureTenancyEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('multi-tenancy.enabled', false)) {
            throw ApiException::notFound();
        }

        return $next($request);
    }
}

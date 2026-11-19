<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * TelescopeAuthorize
 *
 * Middleware personalizado para autorizar acceso a Telescope.
 * Delega en el gate viewTelescope (ver DevToolsAccess).
 */
class TelescopeAuthorize
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Abierto solo en 'local'; en otro entorno exige admin o email autorizado
        if (! Gate::forUser($request->user())->allows('viewTelescope')) {
            abort(403, 'No tienes acceso a Telescope');
        }

        return $next($request);
    }
}

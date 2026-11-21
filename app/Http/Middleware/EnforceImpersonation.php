<?php

namespace App\Http\Middleware;

use App\Exceptions\ImpersonationException;
use App\Models\Tenant;
use App\Support\ApiPath;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceImpersonation: vigila las peticiones hechas con un token de impersonación (claim `imp`, ver
 * docs/impersonation.md). Va en el grupo `api`, antes de las rutas, y solo hace algo si la petición trae un token
 * que el guard `api` acepta y que lleva ese claim:
 *
 * - bloquea las rutas sensibles (`impersonation.blocked_routes`) con 403 `impersonation_forbidden`;
 * - si el token está acotado a una empresa (`imp_tenant`), fija `X-Tenant` a ella y rechaza (403
 *   `impersonation_tenant_mismatch`) cualquier otra;
 * - añade `X-Impersonating: <id de soporte>` a la respuesta para que el front muestre el aviso.
 */
class EnforceImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        try {
            // Fuerza al guard a autenticar para que el payload esté disponible; un token inválido simplemente no suma.
            Auth::guard('api')->check();
            $impersonator = Impersonation::actorId();
        } catch (\Throwable) {
            return $next($request);
        }

        if ($impersonator === null) {
            return $next($request);
        }

        if ($this->isBlocked($request)) {
            throw ImpersonationException::routeBlocked();
        }

        $this->pinTenant($request);

        $response = $next($request);
        $response->headers->set('X-Impersonating', $impersonator);

        return $response;
    }

    private function isBlocked(Request $request): bool
    {
        $subject = $request->method().' '.trim(ApiPath::path($request), '/');

        foreach ((array) config('impersonation.blocked_routes', []) as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $subject, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function pinTenant(Request $request): void
    {
        $tenantId = Impersonation::tenantId();

        if ($tenantId === null || ! config('multi-tenancy.enabled', false)) {
            return;
        }

        $header = (string) config('multi-tenancy.resolution.header', 'X-Tenant');
        $sent = $request->headers->get($header);

        if (is_string($sent) && trim($sent) !== '' && trim($sent) !== (string) $tenantId) {
            $pinned = Tenant::query()->find($tenantId);

            if (! $pinned instanceof Tenant || trim($sent) !== (string) $pinned->slug) {
                throw ImpersonationException::tenantMismatch();
            }
        }

        $request->headers->set($header, (string) $tenantId);
    }
}

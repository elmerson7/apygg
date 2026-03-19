<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenant
 *
 * Middleware que resuelve el tenant actual basado en:
 * 1. Header X-Tenant-ID
 * 2. Subdomain
 * 3. Header Authorization (claim 'tenant_id' en JWT)
 *
 * El tenant resuelto se almacena en Tenant::resolveCurrent()
 * y es usado por el trait BelongsToTenant y TenantScope.
 */
class ResolveTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Si multi-tenancy está deshabilitado, continuar sin resolver
        if (! config('multi-tenancy.enabled', false)) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant no encontrado o inválido.',
                'error' => 'tenant_not_found',
            ], 403);
        }

        if (! $tenant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant inactivo.',
                'error' => 'tenant_inactive',
            ], 403);
        }

        // Establecer tenant actual
        Tenant::setCurrent($tenant);

        return $next($request);
    }

    /**
     * Resolver el tenant desde la request
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        // 1. Desde header X-Tenant-ID
        $tenant = $this->resolveFromHeader($request);
        if ($tenant !== null) {
            return $tenant;
        }

        // 2. Desde subdomain
        $tenant = $this->resolveFromSubdomain($request);
        if ($tenant !== null) {
            return $tenant;
        }

        // 3. Desde JWT (claim tenant_id)
        $tenant = $this->resolveFromJwt($request);
        if ($tenant !== null) {
            return $tenant;
        }

        // 4. Tenant por defecto de configuración
        return $this->resolveDefault();
    }

    /**
     * Resolver tenant desde header X-Tenant-ID
     */
    protected function resolveFromHeader(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId === null) {
            return null;
        }

        return Tenant::active()->find($tenantId);
    }

    /**
     * Resolver tenant desde subdomain
     */
    protected function resolveFromSubdomain(Request $request): ?Tenant
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];

        return Tenant::active()->bySlug($subdomain)->first()
            ?? Tenant::active()->byDomain($host)->first();
    }

    /**
     * Resolver tenant desde JWT token
     */
    protected function resolveFromJwt(Request $request): ?Tenant
    {
        $user = $request->user();
        if ($user === null || ! isset($user->tenant_id)) {
            return null;
        }

        return Tenant::active()->find($user->tenant_id);
    }

    /**
     * Resolver tenant por defecto desde configuración
     */
    protected function resolveDefault(): ?Tenant
    {
        $defaultId = config('multi-tenancy.default_tenant_id');
        if ($defaultId === null) {
            return null;
        }

        return Tenant::active()->find($defaultId);
    }
}

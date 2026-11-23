<?php

namespace App\Http\Middleware;

use App\Contracts\LocaleResolverInterface;
use App\Contracts\SubscriptionAccessInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Exceptions\TenantAccessDenied;
use App\Support\Tenancy\Exceptions\TenantSuspended;
use App\Support\Tenancy\PermissionTeams;
use App\Support\Tenancy\TenancyCoherence;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenant (alias `tenant`)
 *
 * Resuelve el tenant activo (cabecera, parámetro de ruta, subdominio o dominio; ver
 * config/multi-tenancy.php), comprueba que el usuario es miembro ACTIVO (o tiene el permiso de
 * plataforma) y fija `TenantContext`. Va DESPUÉS de `auth:api`: `['auth:api', 'tenant']`.
 *
 * `tenant:optional` deja pasar sin tenant. Con multi-tenancy apagado no hace nada.
 *
 * Errores: sin tenant 400 `tenant_required`; tenant inexistente o sin membresía 403
 * `tenant_forbidden` (misma respuesta); tenant no activo 403 `tenant_suspended`.
 */
class ResolveTenant
{
    /** Permiso de plataforma: entrar a cualquier tenant activo sin ser miembro (soporte). */
    public const PLATFORM_PERMISSION = 'tenants.access-any';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LocaleResolverInterface $locale,
        private readonly SubscriptionAccessInterface $access,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if (! $this->context->enabled()) {
            // Apagado con varias empresas en la BD = despliegue incoherente: falla en voz alta (docs/multi-tenancy.md).
            TenancyCoherence::assertCoherent();

            return $next($request);
        }

        // Nada de lo que hubiera antes (otra petición, un test) se hereda.
        $this->context->clear();

        $identifier = $this->identifier($request);

        if ($identifier === null) {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw new MissingTenantContext;
        }

        $user = $request->user('api');

        if (! $user instanceof User) {
            throw TenantAccessDenied::unauthenticated();
        }

        $tenant = $this->find($identifier);

        if (! $tenant instanceof Tenant || ! $this->canAccess($user, $tenant)) {
            throw new TenantAccessDenied;
        }

        if (! $tenant->isActive()) {
            throw new TenantSuspended;
        }

        $this->context->set($tenant);
        // Spatie teams: las relaciones `roles`/`permissions` cacheadas en el usuario son del equipo anterior.
        PermissionTeams::forgetUserRelations($user);
        $request->attributes->set('tenant', $tenant);
        // El idioma del tenant entra en la cadena (usuario -> tenant -> cabecera): se reaplica; SetApiLocale lo restaura.
        App::setLocale($this->locale->locale());

        // Bloqueo por estado de la suscripción (402 en escritura si está impagada o cancelada; docs/billing.md).
        $warning = $this->access->enforce($request, $tenant);

        $response = $next($request);

        if ($warning !== null) {
            $response->headers->set('X-Subscription-Warning', $warning);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }

    private function identifier(Request $request): ?string
    {
        foreach ((array) config('multi-tenancy.resolution.strategies', []) as $strategy) {
            $value = match ($strategy) {
                'header' => $request->header((string) config('multi-tenancy.resolution.header')),
                'route' => $request->route((string) config('multi-tenancy.resolution.route_parameter')),
                'subdomain' => $this->subdomain($request),
                'domain' => 'domain:'.$request->getHost(),
                default => null,
            };

            if ($value instanceof Tenant) {
                return (string) $value->getKey();
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function subdomain(Request $request): ?string
    {
        $base = config('multi-tenancy.resolution.base_domain');
        $host = $request->getHost();

        if (! is_string($base) || $base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $sub = substr($host, 0, -strlen('.'.$base));

        return $sub !== '' && ! str_contains($sub, '.') ? $sub : null;
    }

    private function find(string $identifier): ?Tenant
    {
        if (str_starts_with($identifier, 'domain:')) {
            return Tenant::query()->byDomain(substr($identifier, 7))->first();
        }

        if (ctype_digit($identifier) && ($tenant = Tenant::query()->find((int) $identifier)) instanceof Tenant) {
            return $tenant;
        }

        return Tenant::query()->bySlug($identifier)->first();
    }

    private function canAccess(User $user, Tenant $tenant): bool
    {
        return $user->canEnterTenant($tenant);
    }
}

<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\ImpersonationServiceInterface;
use App\Contracts\SessionServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Exceptions\ImpersonationException;
use App\Models\Blacklist;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\Tenancy\TenantContext;

/**
 * Impersonación de soporte (docs/impersonation.md). Sin estado entre peticiones (Octane).
 *
 * Genera el token con el `TokenService` existente y claims extra (`imp`, `imp_tenant`, `exp` corto); no toca el
 * guard ni el flujo de login/refresh. Quién puede ser suplantado y qué rutas se bloquean está en
 * `config/impersonation.php` y en `EnforceImpersonation`.
 */
class ImpersonationService implements ImpersonationServiceInterface
{
    public const ACTION_START = 'impersonation.start';

    public const ACTION_STOP = 'impersonation.stop';

    public const PERMISSION = 'users.impersonate';

    public function __construct(
        private readonly TokenServiceInterface $tokens,
        private readonly AuditServiceInterface $audit,
        private readonly TenantContext $tenants,
        private readonly SessionServiceInterface $sessions,
    ) {}

    public function start(User $actor, User $target, ?string $reason, ?int $tenantId = null): array
    {
        if (! config('impersonation.enabled')) {
            throw ImpersonationException::disabled();
        }

        if (Impersonation::actorId() !== null) {
            throw ImpersonationException::routeBlocked();
        }

        // Antes de emitir nada: sin motivo no hay impersonación (422 audit_reason_required).
        $reason = $this->audit->requireReason(self::ACTION_START, $reason);

        $this->assertCanImpersonate($actor, $target);
        $tenant = $this->resolveTenant($target, $tenantId);

        $ttl = max(1, (int) config('impersonation.ttl_minutes', 15));

        // Sesión propia del objetivo, con su `sid` (así convive con AUTH_REQUIRE_SESSION=true): se ve en sus
        // sesiones como «Soporte (impersonación)», se revoca al terminar y al cambiar su contraseña.
        $session = $this->sessions->start($target, request()->ip(), 'Soporte (impersonación)');

        $claims = [
            'sid' => $session->public_id,
            Impersonation::CLAIM => $actor->getKey(),
            'exp' => now()->addMinutes($ttl)->timestamp,
        ];

        if ($tenant !== null) {
            $claims[Impersonation::TENANT_CLAIM] = $tenant->getKey();
        }

        $token = $this->tokens->generateAccessToken($target, $claims);

        $this->audit->recordAction(self::ACTION_START, $target, $reason, [
            'target_user_id' => $target->getKey(),
            'ttl_minutes' => $ttl,
            'tenant_id' => $tenant?->getKey(),
        ], $tenant?->getKey());

        LogService::warning('Impersonación iniciada', [
            'impersonator_id' => $actor->getKey(),
            'user_id' => $target->getKey(),
        ], 'security');

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
            'impersonating' => [
                'user_id' => (int) $target->getKey(),
                'impersonator_id' => (int) $actor->getKey(),
                'tenant_id' => $tenant?->getKey(),
            ],
        ];
    }

    public function stop(User $impersonated): void
    {
        $impersonator = Impersonation::actorId();

        if ($impersonator === null) {
            throw ImpersonationException::notImpersonating();
        }

        // `actor_id` = el suplantado e `impersonator_id` = soporte (lo pone AuditService desde el token).
        $this->audit->recordAction(self::ACTION_STOP, $impersonated, null, [
            'impersonator_id' => $impersonator,
        ], Impersonation::tenantId());

        $this->tokens->revokeToken();

        $sid = Impersonation::sessionId();

        if ($sid !== null) {
            $this->sessions->revokeBySid($sid, 'impersonation-stop');
        }
    }

    private function assertCanImpersonate(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ImpersonationException::targetForbidden('No puedes impersonarte a ti mismo.');
        }

        if ($target->isAdmin() || $target->isPlatformAdmin()) {
            throw ImpersonationException::targetForbidden('No se puede impersonar a un super administrador.');
        }

        $targetPermissions = $target->getAllPermissions()->pluck('name');

        if ($targetPermissions->contains(self::PERMISSION)) {
            throw ImpersonationException::targetForbidden('No se puede impersonar a quien también puede impersonar.');
        }

        // Quien no es super admin solo suplanta a quien tiene un subconjunto de sus propios permisos.
        if (! $actor->isAdmin() && $targetPermissions->diff($actor->getAllPermissions()->pluck('name'))->isNotEmpty()) {
            throw ImpersonationException::targetForbidden('No se puede impersonar a un usuario con más privilegios que tú.');
        }

        if (Blacklist::blocksUser($target)) {
            throw ImpersonationException::targetForbidden('No se puede impersonar a un usuario bloqueado.');
        }
    }

    private function resolveTenant(User $target, ?int $tenantId): ?Tenant
    {
        if ($tenantId === null) {
            return null;
        }

        if (! $this->tenants->enabled()) {
            throw ImpersonationException::targetForbidden('La multiempresa no está activada.');
        }

        $tenant = Tenant::query()->find($tenantId);

        // Misma respuesta si no existe o el usuario no es miembro: no revela qué empresas existen.
        if (! $tenant instanceof Tenant || ! $tenant->isActive() || ! $target->isActiveMemberOf($tenant)) {
            throw ImpersonationException::targetForbidden('El usuario no es miembro activo de esa empresa.');
        }

        return $tenant;
    }
}

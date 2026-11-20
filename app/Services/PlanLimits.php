<?php

namespace App\Services;

use App\Contracts\PlanLimitsInterface;
use App\Enums\SubscriptionStatus;
use App\Exceptions\PlanLimitReachedException;
use App\Exceptions\PlanLimitUnknownException;
use App\Models\Tenant;
use App\Models\TenantLimitOverride;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * PlanLimits
 *
 * Límites de `config/plans.php` con override por tenant en BD. Estado del tenant en caché corta con
 * clave explícita y global (la escribe la plataforma). Concurrencia: `guarded()` bloquea la fila del
 * tenant con `FOR UPDATE` dentro de la transacción del alta; sin estado entre peticiones (Octane).
 */
class PlanLimits implements PlanLimitsInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function enabled(): bool
    {
        return (bool) config('plans.enabled', false) && $this->tenants->enabled();
    }

    public function limit(string $key, ?int $tenantId = null): ?int
    {
        $this->assertDeclared($key);

        $tenantId ??= $this->tenants->enforcing() ? $this->tenants->id() : null;

        if (! $this->enabled() || $tenantId === null) {
            return null;
        }

        $state = $this->state($tenantId);

        if (array_key_exists($key, $state['overrides'])) {
            return $state['overrides'][$key];
        }

        $value = config("plans.plans.{$state['plan']}.limits.{$key}");

        return $value === null ? null : (int) $value;
    }

    public function includesFeature(string $feature, ?int $tenantId = null): ?bool
    {
        $tenantId ??= $this->tenants->enforcing() ? $this->tenants->id() : null;

        if (! $this->enabled() || $tenantId === null) {
            return null;
        }

        $gated = false;

        foreach ((array) config('plans.plans', []) as $plan) {
            $features = (array) ($plan['features'] ?? []);

            if (in_array($feature, $features, true)) {
                $gated = true;
            }
        }

        if (! $gated) {
            return null;
        }

        $features = (array) config("plans.plans.{$this->state($tenantId)['plan']}.features", []);

        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    public function check(string $key, int $current, int $adding = 1, ?int $tenantId = null): bool
    {
        $limit = $this->limit($key, $tenantId);

        return $limit === null || $current + $adding <= $limit;
    }

    public function ensureWithin(string $key, int $current, int $adding = 1, ?int $tenantId = null, int $unit = 1): void
    {
        $limit = $this->limit($key, $tenantId);

        if ($limit !== null && $current + $adding > $limit * $unit) {
            throw new PlanLimitReachedException($key, $limit * $unit, $current);
        }
    }

    public function guarded(string $key, \Closure $count, \Closure $create, int $adding = 1, int $unit = 1): mixed
    {
        $tenantId = $this->tenants->enforcing() ? $this->tenants->id() : null;

        if (! $this->enabled() || $tenantId === null) {
            return $create();
        }

        return DB::transaction(function () use ($key, $count, $create, $adding, $tenantId, $unit) {
            // Serializa las altas del mismo tenant: la segunda espera a que la primera confirme.
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();

            $this->ensureWithin($key, $count(), $adding, $tenantId, $unit);

            return $create();
        });
    }

    public function overview(int $tenantId): array
    {
        $state = $this->state($tenantId);
        $limits = [];

        foreach (array_keys((array) config('plans.limits', [])) as $key) {
            $overridden = array_key_exists($key, $state['overrides']);
            $planValue = config("plans.plans.{$state['plan']}.limits.{$key}");

            $limits[$key] = [
                'limit' => $overridden ? $state['overrides'][$key] : ($planValue === null ? null : (int) $planValue),
                'source' => $overridden ? 'override' : 'plan',
                'description' => config("plans.limits.{$key}.description"),
            ];
        }

        return [
            'enabled' => $this->enabled(),
            'plan' => $state['plan'],
            'limits' => $limits,
            'features' => array_values(array_map('strval', (array) config("plans.plans.{$state['plan']}.features", []))),
        ];
    }

    public function setPlan(int $tenantId, ?string $plan): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $tenant->plan = $plan;
        $tenant->save();

        $this->forget($tenantId);
        LogService::logActivity('updated', Tenant::class, $tenantId, ['plan' => $plan]);
    }

    public function setOverride(int $tenantId, string $key, ?int $value): void
    {
        $this->assertDeclared($key);
        Tenant::query()->findOrFail($tenantId);

        TenantLimitOverride::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => $key], ['value' => $value]);

        $this->forget($tenantId);
        LogService::logActivity('updated', Tenant::class, $tenantId, ['limit_override' => $key, 'value' => $value]);
    }

    public function clearOverride(int $tenantId, string $key): void
    {
        $this->assertDeclared($key);
        Tenant::query()->findOrFail($tenantId);

        TenantLimitOverride::query()->where('tenant_id', $tenantId)->where('key', $key)->delete();

        $this->forget($tenantId);
    }

    public function refresh(int $tenantId): void
    {
        $this->forget($tenantId);
    }

    private function assertDeclared(string $key): void
    {
        if (! array_key_exists($key, (array) config('plans.limits', []))) {
            throw PlanLimitUnknownException::forKey($key);
        }
    }

    /**
     * @return array{plan: string, overrides: array<string, int|null>}
     */
    private function state(int $tenantId): array
    {
        return $this->tenants->withoutTenant(fn () => CacheService::remember(
            $this->cacheKey($tenantId),
            (int) config('plans.cache_ttl', 60),
            function () use ($tenantId): array {
                // La suscripción con derecho a uso (en prueba, activa o en gracia por impago) manda sobre `tenants.plan`,
                // que queda como respaldo y para asignar planes a mano sin facturación (docs/billing.md).
                $plan = DB::table('subscriptions')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', SubscriptionStatus::accessValues())
                    ->whereNull('deleted_at')
                    ->value('plan_code') ?? Tenant::query()->whereKey($tenantId)->value('plan');

                return [
                    'plan' => is_string($plan) && config("plans.plans.{$plan}") !== null ? $plan : (string) config('plans.default', 'free'),
                    'overrides' => TenantLimitOverride::query()->where('tenant_id', $tenantId)->pluck('value', 'key')->all(),
                ];
            }
        ));
    }

    private function forget(int $tenantId): void
    {
        $this->tenants->withoutTenant(fn () => CacheService::forget($this->cacheKey($tenantId)));
    }

    private function cacheKey(int $tenantId): string
    {
        return "plans:tenant:{$tenantId}";
    }
}

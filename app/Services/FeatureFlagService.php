<?php

namespace App\Services;

use App\Contracts\FeatureFlagServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Exceptions\FeatureFlagException;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Features\Rollout;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * FeatureFlagService
 *
 * Las flags son de PLATAFORMA (la BD no está aislada por tenant: un override apunta a un tenant,
 * no pertenece a él). Caché corta con claves explícitas y globales (`features:*`, fuera del prefijo
 * de tenant) para que una escritura de plataforma invalide lo que lee cada tenant. Sin estado
 * entre peticiones (Octane) y sin `rand()`: el porcentaje es un hash estable (`Rollout`).
 */
class FeatureFlagService implements FeatureFlagServiceInterface
{
    private const DEFINITIONS_KEY = 'features:definitions';

    public function __construct(private readonly TenantContext $tenants, private readonly PlanLimitsInterface $plans) {}

    public function enabled(string $key, ?User $user = null): bool
    {
        $userId = $user?->getKey() ?? Auth::guard('api')->id();

        return $this->enabledFor(
            $key,
            $userId === null ? null : (int) $userId,
            $this->tenants->enforcing() ? $this->tenants->id() : null,
        );
    }

    public function disabled(string $key, ?User $user = null): bool
    {
        return ! $this->enabled($key, $user);
    }

    public function enabledFor(string $key, ?int $userId = null, ?int $tenantId = null): bool
    {
        if (! $this->declared($key)) {
            return false;
        }

        if ($userId !== null) {
            $override = $this->overrides('user', $userId)[$key] ?? null;

            if ($override !== null) {
                return $override;
            }
        }

        if ($tenantId !== null) {
            $override = $this->overrides('tenant', $tenantId)[$key] ?? null;

            if ($override !== null) {
                return $override;
            }
        }

        // Feature de plan (`config/plans.php` -> `features`): si el plan del tenant no la incluye, queda apagada
        // (un override explícito de arriba, concedido por la plataforma, sí manda sobre el plan).
        if ($tenantId !== null && $this->plans->includesFeature($key, $tenantId) === false) {
            return false;
        }

        [$enabled, $percentage, $by] = $this->globalState($key);

        if (! $enabled || $percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        // Sujeto del hash: el pedido por la flag; si falta, el otro; sin ninguno, apagada.
        $subject = $by === 'tenant'
            ? ($tenantId !== null ? ['tenant', $tenantId] : ($userId !== null ? ['user', $userId] : null))
            : ($userId !== null ? ['user', $userId] : ($tenantId !== null ? ['tenant', $tenantId] : null));

        return $subject !== null && Rollout::includes($key, $subject[0], $subject[1], $percentage);
    }

    public function all(?User $user = null): array
    {
        $result = [];

        foreach (array_keys($this->declaredFlags()) as $key) {
            $result[$key] = [
                'enabled' => $this->enabled($key, $user),
                'description' => config("features.{$key}.description"),
            ];
        }

        return $result;
    }

    public function platformView(): array
    {
        $rows = FeatureFlag::query()->get()->keyBy('key');
        $overrides = FeatureFlagOverride::query()->get()->groupBy('feature_key');

        $view = [];

        foreach (array_keys($this->declaredFlags()) as $key) {
            [$enabled, $percentage, $by] = $this->globalState($key);
            $flagOverrides = $overrides->get($key, collect());

            $view[] = [
                'key' => $key,
                'description' => ($rows->has($key) ? $rows->get($key)?->description : null) ?? config("features.{$key}.description"),
                'enabled' => $enabled,
                'rollout_percentage' => $percentage,
                'rollout_by' => $by,
                'source' => $rows->has($key) ? 'database' : 'config',
                'tenant_overrides' => $flagOverrides->whereNotNull('tenant_id')->mapWithKeys(fn ($o) => [$o->tenant_id => $o->enabled])->all(),
                'user_overrides' => $flagOverrides->whereNotNull('user_id')->mapWithKeys(fn ($o) => [$o->user_id => $o->enabled])->all(),
            ];
        }

        return $view;
    }

    public function setFlag(string $key, array $data): array
    {
        $this->ensureDeclared($key);

        [$enabled, $percentage, $by] = $this->globalState($key);

        FeatureFlag::query()->updateOrCreate(['key' => $key], [
            'enabled' => (bool) ($data['enabled'] ?? $enabled),
            'rollout_percentage' => (int) ($data['rollout_percentage'] ?? $percentage),
            'rollout_by' => (string) ($data['rollout_by'] ?? $by),
            'description' => $data['description'] ?? FeatureFlag::query()->where('key', $key)->value('description'),
        ]);

        $this->forgetDefinitions();
        LogService::logActivity('updated', FeatureFlag::class, 0, ['feature' => $key] + array_intersect_key($data, array_flip(['enabled', 'rollout_percentage', 'rollout_by'])));

        return $this->viewOf($key);
    }

    public function resetFlag(string $key): void
    {
        $this->ensureDeclared($key);

        $tenantIds = FeatureFlagOverride::query()->where('feature_key', $key)->whereNotNull('tenant_id')->pluck('tenant_id');
        $userIds = FeatureFlagOverride::query()->where('feature_key', $key)->whereNotNull('user_id')->pluck('user_id');

        FeatureFlagOverride::query()->where('feature_key', $key)->delete();
        FeatureFlag::query()->where('key', $key)->delete();

        $this->forgetDefinitions();
        $tenantIds->each(fn ($id) => $this->forgetOverrides('tenant', (int) $id));
        $userIds->each(fn ($id) => $this->forgetOverrides('user', (int) $id));
        LogService::logActivity('deleted', FeatureFlag::class, 0, ['feature' => $key]);
    }

    public function setTenantOverride(string $key, int $tenantId, bool $enabled): void
    {
        $this->ensureDeclared($key);
        Tenant::query()->findOrFail($tenantId);

        FeatureFlagOverride::query()->updateOrCreate(
            ['feature_key' => $key, 'tenant_id' => $tenantId],
            ['enabled' => $enabled],
        );

        $this->forgetOverrides('tenant', $tenantId);
        LogService::logActivity('updated', FeatureFlag::class, 0, ['feature' => $key, 'tenant_override' => $tenantId, 'enabled' => $enabled]);
    }

    public function clearTenantOverride(string $key, int $tenantId): void
    {
        $this->ensureDeclared($key);

        FeatureFlagOverride::query()->where('feature_key', $key)->where('tenant_id', $tenantId)->delete();

        $this->forgetOverrides('tenant', $tenantId);
    }

    public function setUserOverride(string $key, int $userId, bool $enabled): void
    {
        $this->ensureDeclared($key);
        User::query()->findOrFail($userId);

        FeatureFlagOverride::query()->updateOrCreate(
            ['feature_key' => $key, 'user_id' => $userId],
            ['enabled' => $enabled],
        );

        $this->forgetOverrides('user', $userId);
        LogService::logActivity('updated', FeatureFlag::class, 0, ['feature' => $key, 'user_override' => $userId, 'enabled' => $enabled]);
    }

    public function clearUserOverride(string $key, int $userId): void
    {
        $this->ensureDeclared($key);

        FeatureFlagOverride::query()->where('feature_key', $key)->where('user_id', $userId)->delete();

        $this->forgetOverrides('user', $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewOf(string $key): array
    {
        foreach ($this->platformView() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        throw FeatureFlagException::unknown($key);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function declaredFlags(): array
    {
        return array_filter((array) config('features', []), 'is_array');
    }

    private function declared(string $key): bool
    {
        return array_key_exists($key, $this->declaredFlags());
    }

    private function ensureDeclared(string $key): void
    {
        if (! $this->declared($key)) {
            throw FeatureFlagException::unknown($key);
        }
    }

    /**
     * Estado global de la flag: la fila de BD manda sobre el config.
     *
     * @return array{0: bool, 1: int, 2: string}
     */
    private function globalState(string $key): array
    {
        $row = $this->definitions()[$key] ?? null;

        if ($row !== null) {
            return [(bool) $row['enabled'], (int) $row['rollout_percentage'], (string) $row['rollout_by']];
        }

        return [
            (bool) config("features.{$key}.enabled", false),
            max(0, min(100, (int) config("features.{$key}.rollout_percentage", 100))),
            config("features.{$key}.rollout_by") === 'tenant' ? 'tenant' : 'user',
        ];
    }

    /**
     * @return array<string, array{enabled: bool, rollout_percentage: int, rollout_by: string}>
     */
    private function definitions(): array
    {
        return $this->cached(self::DEFINITIONS_KEY, fn (): array => FeatureFlag::query()->get()
            ->mapWithKeys(fn (FeatureFlag $f) => [$f->key => [
                'enabled' => $f->enabled,
                'rollout_percentage' => $f->rollout_percentage,
                'rollout_by' => $f->rollout_by,
            ]])->all());
    }

    /**
     * @return array<string, bool>
     */
    private function overrides(string $scope, int $id): array
    {
        $column = $scope === 'tenant' ? 'tenant_id' : 'user_id';

        return $this->cached($this->overridesKey($scope, $id), fn (): array => FeatureFlagOverride::query()
            ->where($column, $id)->get()
            ->mapWithKeys(fn (FeatureFlagOverride $o) => [$o->feature_key => $o->enabled])->all());
    }

    private function overridesKey(string $scope, int $id): string
    {
        return "features:overrides:{$scope}:{$id}";
    }

    private function forgetDefinitions(): void
    {
        $this->tenants->withoutTenant(fn () => CacheService::forget(self::DEFINITIONS_KEY));
    }

    private function forgetOverrides(string $scope, int $id): void
    {
        $this->tenants->withoutTenant(fn () => CacheService::forget($this->overridesKey($scope, $id)));
    }

    /**
     * Caché global (sin segmento `t<id>:`): lo que lee cada tenant lo escribe la plataforma.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function cached(string $key, \Closure $callback): mixed
    {
        return $this->tenants->withoutTenant(
            fn () => CacheService::remember($key, (int) config('settings.cache_ttl', 60), $callback)
        );
    }
}

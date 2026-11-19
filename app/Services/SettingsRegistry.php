<?php

namespace App\Services;

use App\Contracts\SettingsRegistryInterface;
use App\Contracts\SettingsServiceInterface;
use App\Exceptions\SettingException;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingDefinition;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;

/**
 * SettingsRegistry
 *
 * Capa tipada sobre los dos almacenes que ya existían (no hay un sistema paralelo):
 * - plataforma: tabla `settings` vía `SettingsService` (validación, caché, auditoría);
 * - tenant: `tenants.settings` (jsonb plano `clave => valor`), escrito bajo bloqueo de la fila del tenant.
 * Las definiciones salen de `config/settings.php`. Sin estado entre peticiones (Octane): todo se lee
 * de config/caché en cada llamada.
 */
class SettingsRegistry implements SettingsRegistryInterface
{
    private const TENANT_CACHE_KEY = 'settings:tenant';

    public function __construct(
        private readonly SettingsServiceInterface $settings,
        private readonly TenantContext $tenants,
    ) {}

    public function definitions(): array
    {
        return SettingDefinition::all();
    }

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);

        if ($definition->tenant && $this->tenantActive()) {
            $values = $this->tenantValues();

            if (array_key_exists($key, $values)) {
                try {
                    return $definition->normalize($values[$key]);
                } catch (SettingException) {
                    // Valor guardado que ya no cumple la definición (cambió el config): se ignora.
                }
            }
        }

        return $this->platformValue($definition);
    }

    public function platformView(): array
    {
        return array_values(array_map(function (SettingDefinition $definition): array {
            $override = $definition->platform ? $this->platformOverride($definition) : null;

            return $this->describe($definition, $override ?? $definition->defaultValue(), $override !== null ? 'platform' : 'default', $definition->platform);
        }, $this->definitions()));
    }

    public function tenantView(): array
    {
        $view = [];

        foreach ($this->definitions() as $definition) {
            if (! $definition->tenant) {
                continue;
            }

            $view[] = $this->describe($definition, $this->get($definition->key), $this->sourceOf($definition), true);
        }

        return $view;
    }

    public function setPlatform(string $key, mixed $value): mixed
    {
        $definition = $this->definition($key);

        if (! $definition->platform) {
            throw SettingException::notEditable($key, 'plataforma');
        }

        $normalized = $definition->normalize($value);
        $data = ['value' => $definition->encode($normalized), 'type' => $definition->storageType()];

        // Con borrado lógico, la key única puede existir en la papelera: se restaura, no se duplica.
        $row = Settings::withTrashed()->byKey($key)->first();

        if ($row === null) {
            $this->settings->create($data + [
                'key' => $key,
                'group' => $definition->group,
                'description' => $definition->description,
                'is_public' => false,
                'is_encrypted' => false,
            ]);
        } else {
            if ($row->trashed()) {
                $row->restore();
            }
            $this->settings->update((string) $row->id, $data);
        }

        return $normalized;
    }

    public function resetPlatform(string $key): void
    {
        $this->definition($key);

        $row = Settings::byKey($key)->first();

        if ($row !== null) {
            $this->settings->delete((string) $row->id);
        }
    }

    public function setTenant(User $actor, string $key, mixed $value): mixed
    {
        $definition = $this->definition($key);
        $normalized = $definition->normalize($value);

        $this->writeTenant($actor, $definition, fn (array $settings): array => [...$settings, $key => $normalized], 'updated');

        return $normalized;
    }

    public function resetTenant(User $actor, string $key): void
    {
        $definition = $this->definition($key);

        $this->writeTenant($actor, $definition, function (array $settings) use ($key): array {
            unset($settings[$key]);

            return $settings;
        }, 'deleted');
    }

    /**
     * Único punto de escritura de `tenants.settings` (todas las rutas tenant pasan por aquí): exige
     * tenant activo y ajuste editable por tenant, y es donde se comprueba al `$actor` EN ese tenant.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function writeTenant(User $actor, SettingDefinition $definition, callable $change, string $action): void
    {
        $this->guardTenantEditable($definition);

        // El `permission:` de la ruta no basta con equipos apagados: manda el rol del actor EN este tenant.
        TenantRoles::actorRankFor($this->tenants->require(), $actor, 'tenant-settings.manage');

        $this->mutateTenantSettings($actor, $change, $definition->key, $action);
    }

    private function definition(string $key): SettingDefinition
    {
        return SettingDefinition::find($key) ?? throw SettingException::unknown($key);
    }

    private function guardTenantEditable(SettingDefinition $definition): void
    {
        // Falla cerrado: sin tenant activo (o multiempresa apagado) no hay a dónde escribir (400 tenant_required).
        $this->tenants->require();

        if (! $definition->tenant) {
            throw SettingException::notEditable($definition->key, 'tenant');
        }
    }

    /**
     * Lee-modifica-escribe `tenants.settings` bajo `FOR UPDATE`: dos escrituras simultáneas de claves
     * distintas no se pisan.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function mutateTenantSettings(User $actor, callable $change, string $key, string $action): void
    {
        $tenantId = (int) $this->tenants->requireId(Tenant::class, 'settings');

        DB::transaction(function () use ($tenantId, $change): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $tenant->settings = $change($tenant->settings ?? []);
            $tenant->save();
        });

        CacheService::forget(self::TENANT_CACHE_KEY);

        LogService::logActivity($action, Tenant::class, $tenantId, ['setting' => $key, 'actor_id' => $actor->getKey()]);
    }

    private function tenantActive(): bool
    {
        return $this->tenants->enforcing() && $this->tenants->id() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantValues(): array
    {
        $tenantId = $this->tenants->id();

        // CacheService prefija `t<id>:` solo: la copia de un tenant nunca la lee otro.
        return CacheService::remember(
            self::TENANT_CACHE_KEY,
            (int) config('settings.cache_ttl', 60),
            fn (): array => (array) (Tenant::query()->whereKey($tenantId)->value('settings') ?? [])
        );
    }

    private function platformOverride(SettingDefinition $definition): mixed
    {
        $row = $this->settings->findByKey($definition->key);

        return $row === null ? null : $definition->decode($row->value);
    }

    private function platformValue(SettingDefinition $definition): mixed
    {
        if (! $definition->platform) {
            return $definition->defaultValue();
        }

        return $this->platformOverride($definition) ?? $definition->defaultValue();
    }

    private function sourceOf(SettingDefinition $definition): string
    {
        if ($definition->tenant && $this->tenantActive() && array_key_exists($definition->key, $this->tenantValues())) {
            return 'tenant';
        }

        return $definition->platform && $this->platformOverride($definition) !== null ? 'platform' : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SettingDefinition $definition, mixed $value, string $source, bool $editable): array
    {
        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'value' => $value,
            'source' => $source,
            'default' => $definition->defaultValue(),
            'editable' => $editable,
            'values' => $definition->values ?: null,
            'min' => $definition->min,
            'max' => $definition->max,
            'group' => $definition->group,
            'description' => $definition->description,
        ];
    }
}

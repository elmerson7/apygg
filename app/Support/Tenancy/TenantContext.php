<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Tenant activo de la petición, del job o del comando.
 *
 * Se registra como `scoped` (ContractServiceProvider): Laravel/Octane crean una instancia nueva por
 * petición y por job, así que NADA vive en propiedades estáticas ni sobrevive entre peticiones.
 *
 * Al fijar el tenant se escribe además `tenant_id` en el `Context` oculto de Laravel: viaja
 * con cada job que se encole y `RestoreTenantForJob` lo restaura en el worker.
 */
class TenantContext
{
    /** Clave del Context de Laravel (oculta: no sale en los logs). */
    public const CONTEXT_KEY = 'tenant_id';

    private ?Tenant $tenant = null;

    private bool $withoutTenant = false;

    public function enabled(): bool
    {
        return (bool) config('multi-tenancy.enabled', false);
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        $id = $this->tenant?->getKey();

        return $id === null ? null : (int) $id;
    }

    /**
     * @throws MissingTenantContext
     */
    public function require(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    /**
     * Id del tenant activo o MissingTenantContext (lo usan el scope y los hooks del modelo).
     *
     * @throws MissingTenantContext
     */
    public function requireId(string $model, string $operation): int
    {
        return $this->id() ?? throw MissingTenantContext::forModel($model, $operation);
    }

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        Context::addHidden(self::CONTEXT_KEY, (int) $tenant->getKey());
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->withoutTenant = false;
        Context::forgetHidden(self::CONTEXT_KEY);
    }

    /**
     * Ejecuta el callback con ese tenant y restaura el anterior (o ninguno) al terminar,
     * también si lanza. Es la forma de trabajar en un tenant desde un comando, un job de
     * plataforma o un test.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousFlag = $this->withoutTenant;

        $this->withoutTenant = false;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            if ($previousTenant instanceof Tenant) {
                $this->set($previousTenant);
            } else {
                $this->clear();
            }

            $this->withoutTenant = $previousFlag;
        }
    }

    /**
     * Ejecuta el callback SIN filtro de tenant (tareas de plataforma). Úsalo a través de
     * `TenantQuery::withoutTenant()`, que además deja rastro en el log de seguridad.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutTenant(Closure $callback): mixed
    {
        $previous = $this->withoutTenant;
        $this->withoutTenant = true;

        try {
            return $callback();
        } finally {
            $this->withoutTenant = $previous;
        }
    }

    public function isWithoutTenant(): bool
    {
        return $this->withoutTenant;
    }

    /**
     * ¿Hay que aplicar el aislamiento ahora? Falso con multi-tenancy apagado o dentro de `withoutTenant()`.
     */
    public function enforcing(): bool
    {
        return $this->enabled() && ! $this->withoutTenant;
    }
}

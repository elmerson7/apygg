<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToTenant
 *
 * Trait para modelos que pertenecen a un tenant.
 * Agrega filtro automático por tenant_id y asignación automática al crear.
 */
trait BelongsToTenant
{
    /**
     * Boot del trait
     */
    protected static function bootBelongsToTenant(): void
    {
        // Aplicar scope global de tenant
        static::addGlobalScope(new TenantScope);

        // Asignar tenant_id automáticamente al crear
        static::creating(function (self $model) {
            if (is_null($model->tenant_id)) {
                $tenant = Tenant::resolveCurrent();
                if ($tenant !== null) {
                    $model->tenant_id = $tenant->getKey();
                }
            }
        });
    }

    /**
     * Relación con el Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope para filtrar por tenant específico
     */
    public function scopeForTenant(Builder $builder, string $tenantId): Builder
    {
        return $builder->withoutGlobalScope(new TenantScope)
            ->where('tenant_id', $tenantId);
    }

    /**
     * Scope para incluir registros de todos los tenants (sin filtro)
     */
    public function scopeAllTenants(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(new TenantScope);
    }

    /**
     * Verificar si el modelo pertenece a un tenant específico
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant_id === $tenantId;
    }
}

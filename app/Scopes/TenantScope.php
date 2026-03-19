<?php

namespace App\Scopes;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope
 *
 * Aplica filtro automático por tenant_id en todas las queries
 * del modelo que usa el trait BelongsToTenant.
 */
class TenantScope implements Scope
{
    /**
     * Aplicar el scope a la query
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::resolveCurrent() !== null) {
            $builder->where(
                $model->qualifyColumn('tenant_id'),
                Tenant::resolveCurrent()->getKey()
            );
        }
    }
}

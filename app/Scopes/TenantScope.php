<?php

namespace App\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope
 *
 * Filtro global por `tenant_id` de los modelos con `BelongsToTenant`. FAIL-CLOSED: con
 * multi-tenancy activo y sin tenant en el contexto lanza `MissingTenantContext` en vez de
 * devolver todo. Solo se salta dentro de `TenantQuery::withoutTenant()`.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->enforcing()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->requireId($model::class, 'read'));
    }
}

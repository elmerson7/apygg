<?php

namespace App\Traits;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * RecordsTenant
 *
 * Para los logs (actividad, API, seguridad): guarda el `tenant_id` activo al crear el registro,
 * para poder FILTRAR por tenant. A diferencia de `BelongsToTenant` NO aísla: no hay scope, no
 * falla sin tenant (un login, un 404 o una tarea de plataforma no tienen tenant) y la plataforma
 * ve todo. Con multi-tenancy apagado no hace nada. Ver docs/multi-tenancy.md.
 *
 * @mixin Model
 */
trait RecordsTenant
{
    protected static function bootRecordsTenant(): void
    {
        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->enabled() && $model->getAttribute('tenant_id') === null && $context->id() !== null) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }
}

<?php

namespace App\Listeners;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;

/**
 * Restaura en el worker el tenant con el que se encoló el job y lo limpia al terminar.
 *
 * El `tenant_id` viaja en el `Context` oculto de Laravel (se serializa en el payload y se
 * hidrata en JobProcessing, antes que este listener). Sin `tenant_id` el job corre SIN tenant:
 * si toca un modelo con `BelongsToTenant`, falla con MissingTenantContext. Si el tenant ya no
 * existe, el job falla (no corre sin contexto).
 *
 * La conexión `sync` se ignora: corre dentro de la petición, con su propio contexto.
 */
class RestoreTenantForJob
{
    public function handle(JobProcessing|JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        // Se resuelve en cada evento: el binding es scoped y el worker lo recrea por job.
        $context = app(TenantContext::class);

        if ($event->connectionName === 'sync' || ! $context->enabled()) {
            return;
        }

        if (! $event instanceof JobProcessing) {
            $context->clear();

            return;
        }

        // Se lee ANTES de limpiar: clear() también borra la clave del Context hidratado.
        $id = Context::getHidden(TenantContext::CONTEXT_KEY);

        $context->clear();

        if ($id === null) {
            return;
        }

        // Se relee del tenant: lanza ModelNotFoundException si ya no existe (el job falla).
        $context->set(Tenant::query()->findOrFail((int) $id));
    }
}

<?php

namespace App\Support\Tenancy;

use App\Helpers\Actor;
use App\Services\LogService;
use Closure;

/**
 * Única vía sancionada para saltarse el aislamiento por tenant (tareas de plataforma: informes
 * globales, migraciones de datos, soporte). Es explícita y deja rastro en el log de seguridad.
 *
 * Para trabajar DENTRO de un tenant concreto desde un job o comando usa
 * `TenantContext::run($tenant, fn)`; es más segura porque sigue filtrando.
 */
final class TenantQuery
{
    /**
     * Ejecuta el callback sin filtro de tenant: lee todos los tenants y puede crear con un
     * `tenant_id` explícito. Cambiar el `tenant_id` de un registro existente sigue prohibido.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  string  $reason  Para qué se hace (queda en el log)
     * @param  bool  $audit  `false` solo para rutas calientes que ya dejan su propio rastro (autenticación
     *                       por API key: `SecurityLogger::logApiKeyUsage`); no lo uses para nada más
     * @return T
     */
    public static function withoutTenant(Closure $callback, string $reason = 'sin motivo', bool $audit = true): mixed
    {
        $context = app(TenantContext::class);

        if ($audit && $context->enabled()) {
            // Solo canal `security` (fichero): `logs_security.event_type` es un enum cerrado.
            LogService::info('tenancy.without_tenant: consulta sin filtro de tenant', [
                'event_type' => 'tenancy.without_tenant',
                'reason' => $reason,
                'tenant_id' => $context->id(),
                'actor_id' => Actor::user()?->getAuthIdentifier(),
            ], 'security');
        }

        return $context->withoutTenant($callback);
    }
}

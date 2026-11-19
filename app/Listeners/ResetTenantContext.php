<?php

namespace App\Listeners;

use App\Support\Tenancy\PermissionTeamsSchema;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Foundation\Application;
use Spatie\Permission\PermissionRegistrar;

/**
 * Listener de Octane (RequestReceived/TaskReceived/TickReceived): cinturón y tirantes. El
 * `TenantContext` es `scoped` y ya se recrea por operación; esto garantiza que nada del
 * tenant anterior sobrevive aunque el binding cambiara.
 */
class ResetTenantContext
{
    public function handle(object $event): void
    {
        $app = property_exists($event, 'sandbox') && $event->sandbox instanceof Application ? $event->sandbox : app();

        if ($app->resolved(TenantContext::class)) {
            $app->make(TenantContext::class)->clear();
        }

        // El PermissionRegistrar es un singleton que sobrevive entre peticiones de Octane: su equipo
        // de permisos debe volver a la plataforma aunque el TenantContext (scoped) aún no exista.
        if (config('permission.teams')) {
            $app->make(PermissionRegistrar::class)->setPermissionsTeamId(PermissionTeamsSchema::PLATFORM_TEAM);
        }
    }
}

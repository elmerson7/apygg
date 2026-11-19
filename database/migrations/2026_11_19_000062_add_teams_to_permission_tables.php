<?php

use App\Support\Tenancy\PermissionTeamsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Equipos de Spatie (roles y permisos por tenant). Solo actúa si `PERMISSION_TEAMS=true` al
 * migrar; en una instalación ya migrada se activa con `php artisan tenancy:enable-teams`
 * (misma lógica). Ver docs/permissions.md y docs/multi-tenancy.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('permission.teams')) {
            return;
        }

        $default = config('multi-tenancy.default_tenant_id');

        PermissionTeamsSchema::up($default ? (int) $default : null);
    }

    public function down(): void
    {
        PermissionTeamsSchema::down();
    }
};

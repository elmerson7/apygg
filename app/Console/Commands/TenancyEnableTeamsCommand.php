<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Models\Tenant;
use App\Support\Tenancy\PermissionTeams;
use App\Support\Tenancy\PermissionTeamsSchema;
use Illuminate\Console\Command;

/**
 * Activa el esquema de equipos (teams) de Spatie en una instalación ya migrada: añade `tenant_id` a
 * `roles`, `model_has_roles` y `model_has_permissions`. Después pon `PERMISSION_TEAMS=true`.
 * Ver docs/permissions.md §Teams. Haz backup antes (`make backup`).
 */
class TenancyEnableTeamsCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'tenancy:enable-teams
                            {--assign-to= : Tenant (id o slug) al que atribuir las asignaciones de roles existentes; los admin además siguen siendo admin de la plataforma. Sin él, quedan en la plataforma}
                            {--resync-roles : Con PERMISSION_TEAMS=true ya puesto: rehace los roles `tenant-*` por tenant desde tenant_user.role}
                            {--revert : Deshace el esquema (DESTRUCTIVO: borra los roles propios de un tenant)}';

    protected $description = 'Añade el esquema de equipos de Spatie (roles y permisos por tenant)';

    public function handle(): int
    {
        if ($this->option('revert')) {
            if (! $this->confirm('Se borrarán los roles propios de cada tenant y se dejará una asignación por rol. ¿Continuar?')) {
                return self::FAILURE;
            }

            PermissionTeamsSchema::down();
            $this->info('Esquema de equipos revertido. Pon PERMISSION_TEAMS=false.');

            return self::SUCCESS;
        }

        if ($this->option('resync-roles')) {
            if (! PermissionTeams::enabled()) {
                $this->error('Pon PERMISSION_TEAMS=true (y permission:cache-reset) antes de resincronizar.');

                return self::FAILURE;
            }

            $this->info(PermissionTeamsSchema::resyncTenantRoles().' membresías resincronizadas.');

            return self::SUCCESS;
        }

        if (PermissionTeamsSchema::isApplied()) {
            $this->info('El esquema de equipos ya está aplicado.');

            return self::SUCCESS;
        }

        $assignTo = null;

        if ($this->option('assign-to') !== null) {
            $identifier = $this->stringOption('assign-to') ?? '';
            $tenant = ctype_digit($identifier) ? Tenant::query()->find((int) $identifier) : Tenant::query()->bySlug($identifier)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("Tenant '{$identifier}' no encontrado.");

                return self::FAILURE;
            }

            $assignTo = (int) $tenant->getKey();
        }

        PermissionTeamsSchema::up($assignTo);

        $this->info('Esquema de equipos aplicado. Pon PERMISSION_TEAMS=true en el .env, ejecuta permission:cache-reset y luego `tenancy:enable-teams --resync-roles` (rehace los roles `tenant-*` por tenant desde tenant_user.role).');

        return self::SUCCESS;
    }
}

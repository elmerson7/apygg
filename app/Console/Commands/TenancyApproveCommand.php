<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Aprueba un tenant creado por alta libre con revisión (`TENANT_SIGNUP_REQUIRE_APPROVAL=true`):
 * pasa de `pending` a `active`. Tarea de plataforma; idempotente sobre tenants ya activos.
 */
class TenancyApproveCommand extends Command
{
    protected $signature = 'tenancy:approve {tenant : Tenant (id o slug)}';

    protected $description = 'Aprueba un tenant pendiente de revisión (pending -> active)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('tenant');
        $tenant = ctype_digit($identifier) ? Tenant::query()->find((int) $identifier) : Tenant::query()->bySlug($identifier)->first();

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant '{$identifier}' no encontrado.");

            return self::FAILURE;
        }

        if ($tenant->status === TenantStatus::Suspended) {
            $this->error('El tenant está suspendido, no pendiente: no se reactiva con este comando.');

            return self::FAILURE;
        }

        $tenant->update(['status' => TenantStatus::Active]);
        $this->info("Tenant '{$tenant->slug}' activo.");

        return self::SUCCESS;
    }
}

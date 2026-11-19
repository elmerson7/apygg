<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asigna a un tenant los registros SIN tenant (`tenant_id` NULL) de los recursos por empresa:
 * archivos, webhooks, entregas de webhooks y API keys.
 *
 * Al activar multiempresa en una instalación que ya tenía datos, esas filas quedan invisibles para
 * todos los tenants (el scope exige `tenant_id = <activo>`); este comando las atribuye al tenant
 * por defecto. Es una tarea de plataforma (consulta directa a la tabla, sin scope) y es idempotente.
 * Las rutas de almacenamiento de los archivos antiguos no se mueven (siguen sin `tenants/<id>/`).
 */
class TenancyAssignOrphansCommand extends Command
{
    /** @var list<string> */
    private const TABLES = ['files', 'webhooks', 'webhook_deliveries', 'api_keys'];

    protected $signature = 'tenancy:assign-orphans
                            {tenant : Tenant (id o slug) que recibe los registros sin tenant}
                            {--dry-run : Solo cuenta, no cambia nada}';

    protected $description = 'Asigna a un tenant los archivos, webhooks y API keys que no tienen tenant';

    public function handle(): int
    {
        $identifier = (string) $this->argument('tenant');
        $tenant = ctype_digit($identifier) ? Tenant::query()->find((int) $identifier) : Tenant::query()->bySlug($identifier)->first();

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant '{$identifier}' no encontrado.");

            return self::FAILURE;
        }

        foreach (self::TABLES as $table) {
            $query = DB::table($table)->whereNull('tenant_id');
            $count = $this->option('dry-run') ? $query->count() : $query->update(['tenant_id' => $tenant->getKey()]);

            $this->line(sprintf('%s: %d %s', $table, $count, $this->option('dry-run') ? 'por asignar' : 'asignados'));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\TenantMembershipStatus;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
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
                            {--user= : Solo asigna las filas de este usuario (id), que debe ser miembro activo del tenant}
                            {--dry-run : Solo cuenta y lista por dueño, no cambia nada}';

    protected $description = 'Asigna a un tenant los archivos, webhooks y API keys que no tienen tenant';

    public function handle(): int
    {
        $identifier = (string) $this->argument('tenant');
        $tenant = ctype_digit($identifier) ? Tenant::query()->find((int) $identifier) : Tenant::query()->bySlug($identifier)->first();

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant '{$identifier}' no encontrado.");

            return self::FAILURE;
        }

        // Solo se asignan filas cuyo dueño (`user_id`) es miembro ACTIVO del tenant destino: atribuir lo
        // de un usuario ajeno a esa empresa lo expondría a sus miembros. `--user` acota a un dueño.
        $members = DB::table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('status', TenantMembershipStatus::Active->value)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->option('user') !== null) {
            $userId = (int) $this->option('user');

            if (! in_array($userId, $members, true)) {
                $this->error("El usuario {$userId} no es miembro activo del tenant '{$identifier}'.");

                return self::FAILURE;
            }

            $members = [$userId];
        }

        $dry = (bool) $this->option('dry-run');
        $skipped = 0;

        foreach (self::TABLES as $table) {
            // Las entregas no tienen dueño propio: heredan el de su webhook.
            $owner = $table === 'webhook_deliveries' ? 'webhooks.user_id' : "{$table}.user_id";
            $base = fn () => $this->orphans($table);

            $mine = $base()->whereIn($owner, $members);
            $skipped += $this->option('user') === null
                ? $base()->where(fn ($q) => $q->whereNotIn($owner, $members)->orWhereNull($owner))->count()
                : 0;

            if ($dry) {
                $this->line(sprintf('%s: %d por asignar', $table, (clone $mine)->count()));

                foreach ((clone $mine)->selectRaw("{$owner} as owner_id, count(*) as total")->groupBy($owner)->orderBy($owner)->get() as $row) {
                    $this->line(sprintf('  usuario %d: %d', $row->owner_id, $row->total));
                }

                continue;
            }

            $ids = $mine->pluck("{$table}.id");
            $this->line(sprintf('%s: %d asignados', $table, DB::table($table)->whereIn('id', $ids)->update(['tenant_id' => $tenant->getKey()])));
        }

        if ($skipped > 0) {
            $this->warn("{$skipped} fila(s) sin tenant pertenecen a usuarios que NO son miembros activos del tenant; no se tocaron.");
        }

        return self::SUCCESS;
    }

    /**
     * Filas sin tenant de la tabla (las entregas se unen a su webhook para conocer al dueño).
     */
    private function orphans(string $table): Builder
    {
        $query = DB::table($table)->whereNull("{$table}.tenant_id");

        return $table === 'webhook_deliveries'
            ? $query->join('webhooks', 'webhooks.id', '=', 'webhook_deliveries.webhook_id')
            : $query;
    }
}

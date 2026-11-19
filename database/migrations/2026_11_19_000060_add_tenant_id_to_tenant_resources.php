<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiempresa: los recursos que pertenecen a una empresa llevan `tenant_id`
 * (archivos, webhooks, entregas de webhooks y API keys). Ver docs/multi-tenancy.md.
 *
 * La columna es NULLABLE a propósito: una instalación sin multiempresa (o con filas anteriores a
 * activarlo) no se rompe. Con `MULTI_TENANCY_ENABLED=true` los registros nuevos SIEMPRE la llevan
 * (BelongsToTenant la rellena y falla si no hay tenant); las filas antiguas con NULL quedan
 * invisibles para los tenants hasta asignarlas con `php artisan tenancy:assign-orphans`.
 *
 * FK: cascada en webhooks/entregas/API keys (borrar un tenant borra su configuración);
 * RESTRICT en archivos (los blobs del disco no se borran con la fila: purga antes los archivos).
 */
return new class extends Migration
{
    /** @var array<string, string> tabla => acción al borrar el tenant */
    private array $tables = [
        'files' => 'restrict',
        'webhooks' => 'cascade',
        'webhook_deliveries' => 'cascade',
        'api_keys' => 'cascade',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $onDelete) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($onDelete) {
                $column = $blueprint->foreignId('tenant_id')->nullable()->constrained('tenants');
                $onDelete === 'cascade' ? $column->cascadeOnDelete() : $column->restrictOnDelete();
                $blueprint->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};

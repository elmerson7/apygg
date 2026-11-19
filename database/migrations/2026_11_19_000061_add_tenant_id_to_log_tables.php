<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los logs de actividad, API y seguridad registran el tenant para poder FILTRAR por él, pero NO
 * se aíslan: la plataforma los ve todos. Columna nullable (peticiones sin tenant, instalaciones
 * sin multiempresa) con índice y SIN clave foránea: son tablas de solo-añadir y alto volumen, y
 * un log no debe desaparecer ni bloquear nada si el tenant se borra.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['logs_activity', 'logs_api', 'logs_security'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable();
                $blueprint->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};

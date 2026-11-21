<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tablas pivote de spatie/laravel-permission: model_has_permissions, model_has_roles y
 * role_has_permissions. Con `PERMISSION_TEAMS=true` al migrar, las dos primeras llevan la columna
 * de equipo (tenant_id) en la clave primaria. Ver docs/permissions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = (bool) config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'];
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $morphKey = $columnNames['model_morph_key'];

        throw_if(empty($tableNames), 'config/permission.php no cargado. Ejecuta [php artisan config:clear].');

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $teams, $teamKey, $pivotPermission, $morphKey) {
            $table->unsignedBigInteger($pivotPermission);
            $table->string('model_type');
            $table->unsignedBigInteger($morphKey);
            $table->index([$morphKey, 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)->references('id')->on($tableNames['permissions'])->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($teamKey);
                $table->index($teamKey, 'model_has_permissions_team_foreign_key_index');
                $table->primary([$teamKey, $pivotPermission, $morphKey, 'model_type'], 'model_has_permissions_permission_model_type_primary');
            } else {
                $table->primary([$pivotPermission, $morphKey, 'model_type'], 'model_has_permissions_permission_model_type_primary');
            }
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $teams, $teamKey, $pivotRole, $morphKey) {
            $table->unsignedBigInteger($pivotRole);
            $table->string('model_type');
            $table->unsignedBigInteger($morphKey);
            $table->index([$morphKey, 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)->references('id')->on($tableNames['roles'])->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($teamKey);
                $table->index($teamKey, 'model_has_roles_team_foreign_key_index');
                $table->primary([$teamKey, $pivotRole, $morphKey, 'model_type'], 'model_has_roles_role_model_type_primary');
            } else {
                $table->primary([$pivotRole, $morphKey, 'model_type'], 'model_has_roles_role_model_type_primary');
            }
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)->references('id')->on($tableNames['permissions'])->cascadeOnDelete();
            $table->foreign($pivotRole)->references('id')->on($tableNames['roles'])->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        $this->forgetCache();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);

        $this->forgetCache();
    }

    private function forgetCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // Caché no disponible (p. ej. Redis caído en un despliegue): no debe romper la migración.
        }
    }
};

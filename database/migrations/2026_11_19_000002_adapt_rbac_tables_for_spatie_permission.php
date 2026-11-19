<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lleva el RBAC propio (role_permission, user_role, user_permission) al esquema
 * de spatie/laravel-permission, sin reescribir las migraciones originales.
 *
 * - roles y permissions: añade guard_name (los datos existentes quedan en 'api')
 *   y cambia la unicidad de `name` a (`name`, `guard_name`). Se conservan
 *   display_name, description, resource, action y deleted_at.
 * - Crea model_has_roles, model_has_permissions y role_has_permissions y copia
 *   en ellas los datos de las pivotes propias.
 *
 * Las pivotes propias NO se borran aquí: lo hace la migración siguiente, para
 * que un fallo no deje datos a medias. Con teams apagado (por defecto) el
 * esquema es el estándar del paquete; si PERMISSION_TEAMS=true desde el primer
 * `migrate`, se crea con la columna de equipo (tenant_id). Ver docs/permissions.md.
 */
return new class extends Migration
{
    private const GUARD = 'api';

    private const USER_MODEL = 'App\\Models\\User';

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

        // ── roles / permissions ─────────────────────────────────────────────
        Schema::table($tableNames['roles'], function (Blueprint $table) use ($teams, $teamKey) {
            $table->string('guard_name')->default(self::GUARD);
            $table->dropUnique('roles_name_unique');
            if ($teams) {
                $table->unsignedBigInteger($teamKey)->nullable()->after('id');
                $table->index($teamKey, 'roles_team_foreign_key_index');
                $table->unique([$teamKey, 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->string('guard_name')->default(self::GUARD);
            $table->dropUnique('permissions_name_unique');
            $table->unique(['name', 'guard_name']);
        });

        // El valor por defecto solo servía para rellenar lo existente: el modelo
        // siempre fija guard_name y el paquete lo exige explícito.
        DB::statement("ALTER TABLE {$tableNames['roles']} ALTER COLUMN guard_name DROP DEFAULT");
        DB::statement("ALTER TABLE {$tableNames['permissions']} ALTER COLUMN guard_name DROP DEFAULT");

        // ── tablas de Spatie ────────────────────────────────────────────────
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

        // ── copiar datos de las pivotes propias ─────────────────────────────
        // Con teams encendido, las asignaciones existentes se atribuyen al tenant por defecto.
        $teamValue = (int) (config('multi-tenancy.default_tenant_id') ?: 1);
        $teamColumn = $teams ? ", {$teamKey}" : '';
        $teamSelect = $teams ? ", {$teamValue}" : '';

        if (Schema::hasTable('user_role')) {
            DB::statement(
                "INSERT INTO {$tableNames['model_has_roles']} ({$pivotRole}, model_type, {$morphKey}{$teamColumn})
                 SELECT role_id, ?, user_id{$teamSelect} FROM user_role",
                [self::USER_MODEL]
            );
        }

        if (Schema::hasTable('user_permission')) {
            DB::statement(
                "INSERT INTO {$tableNames['model_has_permissions']} ({$pivotPermission}, model_type, {$morphKey}{$teamColumn})
                 SELECT permission_id, ?, user_id{$teamSelect} FROM user_permission",
                [self::USER_MODEL]
            );
        }

        if (Schema::hasTable('role_permission')) {
            DB::statement(
                "INSERT INTO {$tableNames['role_has_permissions']} ({$pivotPermission}, {$pivotRole})
                 SELECT permission_id, role_id FROM role_permission"
            );
        }

        $this->forgetCache();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'];
        $teams = Schema::hasColumn($tableNames['roles'], $teamKey);

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);

        Schema::table($tableNames['roles'], function (Blueprint $table) use ($teams, $teamKey) {
            if ($teams) {
                $table->dropUnique([$teamKey, 'name', 'guard_name']);
                $table->dropIndex('roles_team_foreign_key_index');
                $table->dropColumn($teamKey);
            } else {
                $table->dropUnique(['name', 'guard_name']);
            }
            $table->dropColumn('guard_name');
            $table->unique('name');
        });

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->dropColumn('guard_name');
            $table->unique('name');
        });

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

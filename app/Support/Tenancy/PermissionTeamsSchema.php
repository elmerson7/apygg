<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Esquema de equipos (teams) de Spatie sobre una instalación existente: añade `tenant_id`
 * (config `permission.column_names.team_foreign_key`) a `roles`, `model_has_roles` y
 * `model_has_permissions`. Es la guía oficial de Spatie (`add_teams_fields`) con tres cambios:
 *
 * - El valor por defecto de las pivotes es `PLATFORM_TEAM` (0), no 1: «sin tenant» = plataforma.
 * - `roles.tenant_id` NULL = rol GLOBAL (catálogo común a todos los tenants); la unicidad
 *   `(tenant_id, name, guard_name)` es `NULLS NOT DISTINCT` para que dos roles globales no
 *   puedan llamarse igual (el UNIQUE normal de PostgreSQL trata los NULL como distintos).
 * - Las claves primarias se nombran de forma explícita (así existen desde la migración
 *   `000002`), por lo que se pueden borrar sin adivinar el nombre.
 *
 * Reversible y idempotente. Lo usan la migración `…000062` (si `PERMISSION_TEAMS=true` al migrar)
 * y el comando `tenancy:enable-teams` (instalaciones ya migradas). Ver docs/permissions.md.
 */
final class PermissionTeamsSchema
{
    /** Equipo de la plataforma: asignaciones que no pertenecen a ningún tenant (admin global, soporte). */
    public const PLATFORM_TEAM = 0;

    public static function isApplied(): bool
    {
        return Schema::hasColumn(self::roles(), self::teamKey());
    }

    /**
     * @param  int|null  $assignTo  tenant al que atribuir las asignaciones existentes (null = plataforma)
     */
    public static function up(?int $assignTo = null): void
    {
        $tables = config('permission.table_names');
        $teamKey = self::teamKey();
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');
        $pivotRole = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $default = $assignTo ?? self::PLATFORM_TEAM;

        DB::transaction(function () use ($tables, $teamKey, $morphKey, $pivotRole, $pivotPermission, $default, $assignTo) {
            if (! Schema::hasColumn($tables['roles'], $teamKey)) {
                Schema::table($tables['roles'], function (Blueprint $table) use ($teamKey) {
                    $table->unsignedBigInteger($teamKey)->nullable()->after('id');
                    $table->index($teamKey, 'roles_team_foreign_key_index');
                    $table->dropUnique(['name', 'guard_name']);
                });

                DB::statement(
                    "ALTER TABLE {$tables['roles']} ADD CONSTRAINT roles_{$teamKey}_name_guard_name_unique UNIQUE NULLS NOT DISTINCT ({$teamKey}, name, guard_name)"
                );
            }

            foreach ([
                ['table' => $tables['model_has_permissions'], 'pivot' => $pivotPermission, 'parent' => $tables['permissions'], 'pk' => 'model_has_permissions_permission_model_type_primary', 'idx' => 'model_has_permissions_team_foreign_key_index'],
                ['table' => $tables['model_has_roles'], 'pivot' => $pivotRole, 'parent' => $tables['roles'], 'pk' => 'model_has_roles_role_model_type_primary', 'idx' => 'model_has_roles_team_foreign_key_index'],
            ] as $p) {
                if (Schema::hasColumn($p['table'], $teamKey)) {
                    continue;
                }

                Schema::table($p['table'], function (Blueprint $table) use ($p, $teamKey, $morphKey, $default) {
                    $table->unsignedBigInteger($teamKey)->default($default);
                    $table->index($teamKey, $p['idx']);
                    $table->dropForeign([$p['pivot']]);
                    $table->dropPrimary($p['pk']);
                    $table->primary([$teamKey, $p['pivot'], $morphKey, 'model_type'], $p['pk']);
                    $table->foreign($p['pivot'])->references('id')->on($p['parent'])->cascadeOnDelete();
                });
            }

            // El valor por defecto solo sirve para rellenar lo existente: las altas nuevas lo indican siempre.
            foreach ([$tables['model_has_permissions'], $tables['model_has_roles']] as $pivot) {
                DB::statement("ALTER TABLE {$pivot} ALTER COLUMN {$teamKey} DROP DEFAULT");
            }

            // Operador existente: los admin siguen siéndolo también a nivel de plataforma.
            if ($assignTo !== null && $assignTo !== self::PLATFORM_TEAM) {
                DB::statement(
                    "INSERT INTO {$tables['model_has_roles']} ({$teamKey}, {$pivotRole}, {$morphKey}, model_type)
                     SELECT ?, mhr.{$pivotRole}, mhr.{$morphKey}, mhr.model_type
                     FROM {$tables['model_has_roles']} mhr JOIN {$tables['roles']} r ON r.id = mhr.{$pivotRole}
                     WHERE r.name = 'admin' AND mhr.{$teamKey} = ?
                     ON CONFLICT DO NOTHING",
                    [self::PLATFORM_TEAM, $assignTo]
                );
            }
        });

        self::forgetCache();
    }

    /**
     * Vuelve al esquema sin equipos. DESTRUCTIVO: borra los roles propios de un tenant y deja una
     * sola asignación por (rol, modelo) aunque estuviera repetida en varios tenants.
     */
    public static function down(): void
    {
        $tables = config('permission.table_names');
        $teamKey = self::teamKey();
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');
        $pivotRole = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?: 'permission_id';

        DB::transaction(function () use ($tables, $teamKey, $morphKey, $pivotRole, $pivotPermission) {
            if (Schema::hasColumn($tables['roles'], $teamKey)) {
                DB::table($tables['roles'])->whereNotNull($teamKey)->delete();
            }

            foreach ([
                ['table' => $tables['model_has_permissions'], 'pivot' => $pivotPermission, 'parent' => $tables['permissions'], 'pk' => 'model_has_permissions_permission_model_type_primary', 'idx' => 'model_has_permissions_team_foreign_key_index'],
                ['table' => $tables['model_has_roles'], 'pivot' => $pivotRole, 'parent' => $tables['roles'], 'pk' => 'model_has_roles_role_model_type_primary', 'idx' => 'model_has_roles_team_foreign_key_index'],
            ] as $p) {
                if (! Schema::hasColumn($p['table'], $teamKey)) {
                    continue;
                }

                DB::statement(
                    "DELETE FROM {$p['table']} a USING {$p['table']} b
                     WHERE a.ctid < b.ctid AND a.{$p['pivot']} = b.{$p['pivot']} AND a.{$morphKey} = b.{$morphKey} AND a.model_type = b.model_type"
                );

                Schema::table($p['table'], function (Blueprint $table) use ($p, $teamKey, $morphKey) {
                    $table->dropForeign([$p['pivot']]);
                    $table->dropPrimary($p['pk']);
                    $table->dropIndex($p['idx']);
                    $table->dropColumn($teamKey);
                    $table->primary([$p['pivot'], $morphKey, 'model_type'], $p['pk']);
                    $table->foreign($p['pivot'])->references('id')->on($p['parent'])->cascadeOnDelete();
                });
            }

            if (Schema::hasColumn($tables['roles'], $teamKey)) {
                DB::statement("ALTER TABLE {$tables['roles']} DROP CONSTRAINT roles_{$teamKey}_name_guard_name_unique");
                Schema::table($tables['roles'], function (Blueprint $table) use ($teamKey) {
                    $table->dropIndex('roles_team_foreign_key_index');
                    $table->dropColumn($teamKey);
                    $table->unique(['name', 'guard_name']);
                });
            }
        });

        self::forgetCache();
    }

    private static function roles(): string
    {
        return (string) config('permission.table_names.roles', 'roles');
    }

    private static function teamKey(): string
    {
        return (string) config('permission.column_names.team_foreign_key', 'tenant_id');
    }

    private static function forgetCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            // Caché no disponible: no debe romper la migración.
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles (spatie/laravel-permission, guard `api`) con los campos propios del boilerplate
 * (display_name, description, deleted_at). Con `PERMISSION_TEAMS=true` al migrar se crea con
 * la columna de equipo (tenant_id); en una instalación ya migrada se activa con
 * `php artisan tenancy:enable-teams`. Ver docs/permissions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('permission.table_names.roles', 'roles');
        $teams = (bool) config('permission.teams');
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');

        Schema::create($tableName, function (Blueprint $table) use ($teams, $teamKey) {
            $table->id();
            if ($teams) {
                $table->unsignedBigInteger($teamKey)->nullable();
                $table->index($teamKey, 'roles_team_foreign_key_index');
            }
            $table->string('name');
            $table->string('guard_name');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
            $table->index('created_at');

            if ($teams) {
                $table->unique([$teamKey, 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('permission.table_names.roles', 'roles'));
    }
};

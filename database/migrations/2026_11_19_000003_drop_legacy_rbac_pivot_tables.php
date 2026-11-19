<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borra las pivotes del RBAC propio (role_permission, user_role, user_permission):
 * sus datos ya viven en las tablas de spatie/laravel-permission (migración anterior).
 *
 * down() las recrea y copia de vuelta las asignaciones actuales de Spatie
 * (solo las de App\Models\User; con teams encendido, de cualquier equipo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('user_permission');
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $morphKey = $columnNames['model_morph_key'];

        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');

            $table->unique(['role_id', 'permission_id']);
            $table->index('role_id');
            $table->index('permission_id');
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');

            $table->unique(['user_id', 'role_id']);
            $table->index('user_id');
            $table->index('role_id');
        });

        Schema::create('user_permission', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');

            $table->unique(['user_id', 'permission_id']);
            $table->index('user_id');
            $table->index('permission_id');
        });

        DB::statement(
            "INSERT INTO role_permission (role_id, permission_id, created_at, updated_at)
             SELECT {$pivotRole}, {$pivotPermission}, NOW(), NOW() FROM {$tableNames['role_has_permissions']}"
        );

        DB::statement(
            "INSERT INTO user_role (user_id, role_id, created_at, updated_at)
             SELECT DISTINCT {$morphKey}, {$pivotRole}, NOW(), NOW() FROM {$tableNames['model_has_roles']}
             WHERE model_type = ?",
            ['App\\Models\\User']
        );

        DB::statement(
            "INSERT INTO user_permission (user_id, permission_id, created_at, updated_at)
             SELECT DISTINCT {$morphKey}, {$pivotPermission}, NOW(), NOW() FROM {$tableNames['model_has_permissions']}
             WHERE model_type = ?",
            ['App\\Models\\User']
        );
    }
};

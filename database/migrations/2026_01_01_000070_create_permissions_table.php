<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos (spatie/laravel-permission, guard `api`) con los campos propios del boilerplate
 * (display_name, resource, action, description, deleted_at). Ver docs/permissions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('permission.table_names.permissions', 'permissions'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('display_name');
            $table->string('resource');
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
            $table->index(['resource', 'action']);
            $table->index('created_at');
            $table->unique(['name', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('permission.table_names.permissions', 'permissions'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias regionales con herencia: NULL = heredar (usuario -> tenant -> instalación).
 * `users.timezone` ya existía con DEFAULT 'UTC' (que impedía heredar): pasa a nullable sin default.
 * Los valores 'UTC' ya guardados se conservan como elección explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('timezone');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN timezone DROP DEFAULT');

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone', 'currency']);
        });

        DB::statement("ALTER TABLE users ALTER COLUMN timezone SET DEFAULT 'UTC'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};

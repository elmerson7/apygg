<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('deleted_by')->nullable();

            $table->index('email');
            $table->index('created_at');
            $table->index('deleted_by');
        });

        // Crear password_reset_tokens solo si no existe (tabla del sistema Laravel)
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // Crear sessions solo si no existe (tabla del sistema Laravel)
        // Si ya existe, no la modificamos para evitar conflictos con la estructura original
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->uuid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
        // Nota: Si sessions ya existe con user_id como bigInteger,
        // se mantiene así para compatibilidad con Laravel estándar
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Solo eliminar users, las tablas del sistema se mantienen
        Schema::dropIfExists('users');

        // No eliminar password_reset_tokens ni sessions (tablas del sistema Laravel)
    }
};

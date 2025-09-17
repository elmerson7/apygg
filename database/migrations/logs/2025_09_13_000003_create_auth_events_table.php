<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return 'logs';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('logs')->create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 26)->nullable()->index(); // FK reference to main DB users table (ULID)
            $table->string('event', 50)->index(); // "login", "logout", "refresh", "2fa_success", "2fa_failed", etc.
            $table->string('result', 20)->index(); // "success", "failed", "blocked"
            $table->string('reason', 100)->nullable(); // Razón del fallo o bloqueo
            $table->string('jti', 100)->nullable()->index(); // JWT ID para tokens
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable(); // Datos adicionales (dispositivo, ubicación, etc.)
            $table->string('trace_id', 36)->nullable()->index();
            $table->timestamp('created_at');
            
            // Índices para análisis de seguridad
            $table->index(['user_id', 'event', 'created_at']);
            $table->index(['result', 'created_at']);
            $table->index(['ip', 'result', 'created_at']);
            $table->index(['event', 'result']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('auth_events');
    }
};

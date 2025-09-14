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
        Schema::connection('logs')->create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('severity', 20)->index(); // "low", "medium", "high", "critical"
            $table->string('event', 100)->index(); // "rate_limit_exceeded", "invalid_webhook_signature", "upload_blocked", etc.
            $table->string('user_id', 26)->nullable()->index(); // FK reference to main DB users table (ULID)
            $table->string('ip', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable(); // Contexto específico del evento de seguridad
            $table->string('trace_id', 36)->nullable()->index();
            $table->timestamp('created_at');
            
            // Índices para monitoreo de seguridad
            $table->index(['severity', 'created_at']);
            $table->index(['event', 'severity']);
            $table->index(['ip', 'event', 'created_at']);
            $table->index(['user_id', 'severity', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('security_events');
    }
};

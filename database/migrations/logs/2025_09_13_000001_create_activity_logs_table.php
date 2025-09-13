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
        Schema::connection('logs')->create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // FK reference to main DB users table
            $table->string('event', 100)->index(); // Tipo de evento (ej: "user.created", "post.updated")
            $table->string('subject_type', 100)->nullable()->index(); // Tipo de entidad afectada
            $table->unsignedBigInteger('subject_id')->nullable()->index(); // ID de la entidad afectada
            $table->string('ip', 45)->nullable(); // IPv4 o IPv6
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable(); // Datos adicionales del evento
            $table->string('trace_id', 36)->nullable()->index(); // Para correlación con otros logs
            $table->timestamp('created_at');
            
            // Índices compuestos para búsquedas eficientes
            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('activity_logs');
    }
};

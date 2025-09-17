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
        Schema::connection('logs')->create('api_problem_details', function (Blueprint $table) {
            $table->id();
            $table->string('type', 200)->index(); // URI del tipo de problema RFC-7807
            $table->string('title', 200)->index(); // Título del problema
            $table->integer('status')->index(); // Código de estado HTTP
            $table->text('detail')->nullable(); // Descripción detallada del problema
            $table->string('instance', 200)->nullable(); // URI de la instancia específica
            $table->string('user_id', 26)->nullable()->index(); // FK reference to main DB users table (ULID)
            $table->string('ip', 45)->nullable()->index(); // IP address
            $table->text('user_agent')->nullable(); // User agent string
            $table->string('trace_id', 36)->nullable()->index(); // Para correlación con Sentry
            $table->json('context')->nullable(); // Contexto adicional del error
            $table->timestamp('created_at');
            
            // Índices para análisis de errores
            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
            $table->index(['title', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['ip', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('api_problem_details');
    }
};

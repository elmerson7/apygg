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
        Schema::connection('logs')->create('rbac_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_id', 26)->nullable()->index(); // FK reference to main DB users table (ULID)
            $table->string('user_id', 26)->nullable()->index(); // FK reference to main DB users table (ULID)
            $table->string('action', 50)->index(); // "grant", "revoke", "assign", "remove"
            $table->string('role', 100)->nullable()->index(); // Nombre del rol
            $table->string('permission', 100)->nullable()->index(); // Nombre del permiso
            $table->json('meta')->nullable(); // Datos adicionales (ej: contexto, razón)
            $table->string('trace_id', 36)->nullable()->index(); // Para correlación
            $table->timestamp('created_at');
            
            // Índices para auditoría y compliance
            $table->index(['user_id', 'action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['role', 'action']);
            $table->index(['permission', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('rbac_audit_logs');
    }
};

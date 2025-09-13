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
        Schema::connection('logs')->create('file_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // FK reference to main DB users table
            $table->unsignedBigInteger('file_id')->nullable()->index(); // ID del archivo en el sistema
            $table->string('action', 50)->index(); // "upload", "download", "delete", "view"
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable(); // Metadatos (tamaño, tipo MIME, URL firmada, etc.)
            $table->string('trace_id', 36)->nullable()->index();
            $table->timestamp('created_at');
            
            // Índices para auditoría de archivos
            $table->index(['user_id', 'action', 'created_at']);
            $table->index(['file_id', 'action']);
            $table->index(['action', 'created_at']);
            $table->index(['ip', 'action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('logs')->dropIfExists('file_access_logs');
    }
};

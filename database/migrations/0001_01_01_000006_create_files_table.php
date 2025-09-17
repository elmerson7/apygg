<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('user_id', 26)->nullable(); // Permitir archivos sin usuario
            $table->string('disk', 50)->default('s3'); // s3, local, etc.
            $table->string('path')->index(); // Ruta en el storage
            $table->string('original_name');
            $table->string('mime_type', 100)->index();
            $table->bigInteger('size')->unsigned(); // Tamaño en bytes
            $table->char('checksum', 64)->index(); // SHA256
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->enum('status', ['uploading', 'scanning', 'verified', 'infected', 'failed'])->default('uploading');
            $table->jsonb('meta')->nullable(); // Metadatos adicionales
            $table->timestamp('created_at', 0);
            $table->timestamp('updated_at', 0);
            $table->timestamp('deleted_at', 0)->nullable();
            
            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            // Índices
            $table->index(['user_id', 'deleted_at']);
            $table->index(['mime_type', 'visibility']);
            $table->index(['created_at', 'deleted_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};

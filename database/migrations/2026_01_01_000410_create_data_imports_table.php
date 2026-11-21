<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();   // null = plataforma
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('importer', 64);                       // clave del importador (config/export.php)
            // validating | previewed | queued | processing | completed | failed
            $table->string('status', 16)->default('validating');
            $table->string('original_name');
            $table->string('disk', 32);
            $table->string('path');
            $table->string('file_hash', 64);                      // sha256 del archivo subido
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('valid_rows')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->unsignedBigInteger('created_rows')->default(0);
            $table->unsignedBigInteger('skipped_rows')->default(0);   // ya existían: no se duplican
            $table->jsonb('preview')->nullable();                 // muestra de filas válidas y primeros errores
            $table->string('errors_path')->nullable();            // informe de errores (CSV) en el disco privado
            $table->text('error')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};

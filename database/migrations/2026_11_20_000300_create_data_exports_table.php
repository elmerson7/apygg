<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();   // null = plataforma
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('exporter', 64);                       // clave del exportador (config/export.php)
            $table->string('format', 8);                          // csv | xlsx
            $table->jsonb('filters')->nullable();
            $table->string('status', 16)->default('pending');     // pending | processing | completed | failed
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};

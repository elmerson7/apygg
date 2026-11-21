<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones de datos personales (portabilidad; docs/privacy.md). El ZIP vive en el disco privado y caduca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');   // pending | processing | ready | failed | expired
            $table->string('disk', 32)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_data_exports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_upload_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('filename');
            $table->string('path');
            $table->string('disk', 64);
            $table->string('mime_type', 191);
            $table->string('extension', 16);
            $table->bigInteger('size');                          // bytes declarados: reservan cuota hasta completar o caducar
            $table->string('checksum_sha256', 64)->nullable();   // hex, opcional
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('status', 16)->default('pending');    // pending | completed | aborted
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();                               // base Model: SoftDeletes (la purga borra de verdad)

            $table->index(['tenant_id', 'status', 'expires_at']); // suma de reservas por tenant
            $table->index(['status', 'expires_at']);              // barrido de caducadas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_upload_intents');
    }
};

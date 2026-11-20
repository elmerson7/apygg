<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de consentimientos de un usuario (docs/privacy.md). Solo se AÑADE: el estado actual de un documento es
 * su última fila. Es de la persona (sin `tenant_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_key', 64);
            $table->string('version', 32);
            $table->string('locale', 10)->nullable();
            $table->string('action', 16);                    // accepted | withdrawn
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'document_key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};

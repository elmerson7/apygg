<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos legales versionados (términos, privacidad, marketing...; docs/privacy.md).
 * Son de la PLATAFORMA (sin `tenant_id`). Una `key` tiene varias versiones y cada versión varias traducciones
 * (`locale`). Inmutables: una corrección se publica como versión nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->string('version', 32);
            $table->string('locale', 10);
            $table->string('title', 191);
            $table->text('body');
            $table->string('content_hash', 64);
            $table->timestamp('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'version', 'locale']);
            $table->index(['key', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};

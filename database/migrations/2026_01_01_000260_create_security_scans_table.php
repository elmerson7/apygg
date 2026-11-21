<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escaneos de seguridad.
 *
 * `salt` es por escaneo: los identificadores sensibles se hashean con ella
 * antes de enviarse al LLM, de modo que se pueden correlacionar eventos dentro
 * de un escaneo sin crear un identificador estable entre escaneos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('llm_model')->nullable();
            $table->jsonb('findings')->nullable();
            $table->jsonb('analysis')->nullable();
            $table->string('salt', 64);
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_scans');
    }
};

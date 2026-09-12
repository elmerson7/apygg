<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa LLM.
 *
 * - llm_areas → modelo principal + fallbacks por área, configurables sin desplegar
 * - logs_llm  → auditoría de cada llamada (tokens, estado, actor, trace_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_areas', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // soporte, auditoria, docs…
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('primary_provider')->default('openai');
            $table->string('primary_model')->nullable();
            $table->jsonb('fallbacks')->nullable();        // [{provider, model}, …]
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('logs_llm', function (Blueprint $table) {
            $table->id();
            $table->string('use_case')->index();
            $table->string('module')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('status')->default('success');  // success | error | skipped
            $table->text('error')->nullable();
            $table->text('prompt')->nullable();            // truncado, ver LlmAuditService
            $table->text('response')->nullable();          // truncado
            $table->jsonb('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trace_id')->nullable()->index();
            $table->timestamps();

            $table->index(['use_case', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_llm');
        Schema::dropIfExists('llm_areas');
    }
};

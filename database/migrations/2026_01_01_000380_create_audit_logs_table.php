<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de negocio: quién cambió qué, cuándo y desde dónde (docs/audit.md). Tabla de solo-añadir:
 * sin `updated_at`, sin soft delete y sin clave foránea (el rastro no desaparece si se borra el tenant
 * o el usuario). `tenant_id` es nullable (acciones de plataforma, instalaciones sin multiempresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            // created | updated | deleted | restored (cambios de modelo) o el nombre de una acción (user.roles-change...)
            $table->string('event', 100);
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            // user | api_key | system
            $table->string('actor_type', 20);
            $table->string('actor_id', 64)->nullable();
            // Impersonación (docs/impersonation.md): quien actúa de verdad cuando `actor_id` es el usuario suplantado.
            $table->string('impersonator_id', 64)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('event');
            $table->index('impersonator_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

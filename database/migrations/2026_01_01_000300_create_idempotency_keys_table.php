<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('actor', 64);                  // user:{id} | api_key:{id} | ip:{ip}
            $table->string('key', 255);
            $table->char('token', 36);                    // dueño de la reserva actual (frena a un proceso caducado)
            $table->string('method', 10);
            $table->text('path');
            $table->char('request_hash', 64);             // sha256 del cuerpo canónico
            $table->string('state', 16);                  // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();    // cifrado (Crypt): puede llevar secretos
            $table->timestamp('expires_at');              // fin de la reserva (processing) o de la respuesta guardada
            $table->timestamps();

            $table->index('expires_at');
        });

        // Única por (tenant, actor, clave). COALESCE: en PostgreSQL los NULL no chocan entre sí en un índice único.
        DB::statement('CREATE UNIQUE INDEX idempotency_keys_scope_unique ON idempotency_keys ((COALESCE(tenant_id, 0)), actor, key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

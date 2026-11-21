<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('delivery_id', 191);                  // id de la entrega según el proveedor (idempotencia)
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable();                // solo las cabeceras permitidas; nunca la firma
            $table->string('status', 16)->default('received');   // received | processed | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Idempotencia ATÓMICA: el duplicado lo decide la restricción, no un exists() previo.
            $table->unique(['provider', 'delivery_id']);
            $table->index(['status', 'created_at']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_receipts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un intento de cobro (o un pago manual) de una factura. Se inserta ANTES de llamar a la pasarela
        // (docs/integrations.md §6) con su clave de idempotencia; el reintento tras un corte reutiliza la misma fila.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('method', 16);                           // gateway | manual
            $table->string('gateway', 32)->nullable();
            $table->string('status', 16);                           // pending|succeeded|failed
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('idempotency_key', 128)->unique();
            $table->string('gateway_payment_id')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('reference', 190)->nullable();           // pago manual: referencia bancaria, etc.
            $table->string('reason', 500)->nullable();              // pago manual: motivo
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('tries')->default(0);      // llamadas a la pasarela (errores transitorios)
            $table->timestamp('claimed_until')->nullable();         // arrendamiento: quién cobra ahora
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['invoice_id', 'attempt']);
            $table->index(['status', 'claimed_until']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','succeeded','failed'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('gateway','manual'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_nonneg CHECK (amount_minor >= 0)');
        // Una factura solo puede tener UN pago exitoso (la red de seguridad contra el doble cobro).
        DB::statement("CREATE UNIQUE INDEX payments_one_success_per_invoice ON payments (invoice_id) WHERE status = 'succeeded'");
        // Y un solo intento en curso a la vez.
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_invoice ON payments (invoice_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

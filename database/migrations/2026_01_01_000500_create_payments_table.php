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
            $table->string('status', 16);                           // pending|requires_action|succeeded|failed|refunded
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
            // Reembolso automático de un cobro cuya factura quedó anulada (docs/billing.md §Reembolsos).
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_id')->nullable();
            // El cliente estaba presente al cobrar (puede completar 3DS). Se fija al crear el intento: reintentar con la
            // misma clave de idempotencia debe enviar EXACTAMENTE los mismos parámetros (Stripe rechaza lo contrario).
            $table->boolean('customer_present')->default(false);
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->string('dispute_id', 64)->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['invoice_id', 'attempt']);
            $table->index(['status', 'claimed_until']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','requires_action','succeeded','failed','refunded'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('gateway','manual'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_nonneg CHECK (amount_minor >= 0)');
        // Una factura solo puede tener UN pago exitoso (la red de seguridad contra el doble cobro).
        DB::statement("CREATE UNIQUE INDEX payments_one_success_per_invoice ON payments (invoice_id) WHERE status = 'succeeded'");
        // Y un solo intento en curso a la vez: también cuenta el que espera al cliente (3DS/SCA); si no, se abriría un segundo cobro.
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_invoice ON payments (invoice_id) WHERE status IN ('pending','requires_action')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_amount_valid CHECK (refunded_amount_minor >= 0 AND refunded_amount_minor <= amount_minor)');
        // Los webhooks y la conciliación buscan el pago por el id que da la pasarela.
        DB::statement('CREATE INDEX payments_gateway_payment_idx ON payments (gateway, gateway_payment_id) WHERE gateway_payment_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

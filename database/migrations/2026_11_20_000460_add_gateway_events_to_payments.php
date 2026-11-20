<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado `requires_action` (3DS/SCA: el cliente debe confirmar en el front), reembolsos parciales y disputas
        // que llegan de la pasarela (docs/billing.md §Stripe).
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_valid');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','requires_action','succeeded','failed','refunded'))");

        // Un solo intento en curso por factura: también cuenta el que espera al cliente (si no, se abriría un segundo cobro).
        DB::statement('DROP INDEX payments_one_pending_per_invoice');
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_invoice ON payments (invoice_id) WHERE status IN ('pending','requires_action')");

        Schema::table('payments', function (Blueprint $table) {
            // El cliente estaba presente al cobrar (puede completar 3DS). Se fija al crear el intento: reintentar con la
            // misma clave de idempotencia debe enviar EXACTAMENTE los mismos parámetros (Stripe rechaza lo contrario).
            $table->boolean('customer_present')->default(false);
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->string('dispute_id', 64)->nullable();
            $table->timestamp('disputed_at')->nullable();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_amount_valid CHECK (refunded_amount_minor >= 0 AND refunded_amount_minor <= amount_minor)');
        // Los webhooks y la conciliación buscan el pago por el id que da la pasarela.
        DB::statement('CREATE INDEX payments_gateway_payment_idx ON payments (gateway, gateway_payment_id) WHERE gateway_payment_id IS NOT NULL');
        DB::statement('CREATE INDEX billing_accounts_gateway_customer_idx ON billing_accounts (gateway, gateway_customer_id) WHERE gateway_customer_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_accounts_gateway_customer_idx');
        DB::statement('DROP INDEX IF EXISTS payments_gateway_payment_idx');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refunded_amount_valid');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['customer_present', 'refunded_amount_minor', 'dispute_id', 'disputed_at']);
        });

        DB::statement('DROP INDEX payments_one_pending_per_invoice');
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_invoice ON payments (invoice_id) WHERE status = 'pending'");
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_valid');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','succeeded','failed','refunded'))");
    }
};

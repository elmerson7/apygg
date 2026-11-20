<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete(); // precio facturado
            $table->string('number', 64)->nullable();               // de SequenceInterface, asignado al emitir
            $table->string('status', 16);                           // draft|open|paid|void|uncollectible
            $table->string('billing_reason', 32);                   // subscription_create|subscription_cycle|subscription_update
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('tax_rate', 12)->default('0');           // % como cadena ("18", "7.5"): nunca float
            $table->string('tax_label', 64)->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['subscription_id', 'status']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_valid CHECK (status IN ('draft','open','paid','void','uncollectible'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_consistent CHECK (total_minor = subtotal_minor + tax_minor)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_nonneg CHECK (total_minor >= 0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_number_when_issued CHECK (status = 'draft' OR number IS NOT NULL)");

        // Nunca dos facturas del mismo periodo para una suscripción (alta y renovaciones). Las de ajuste por
        // cambio de plan (`subscription_update`) comparten periodo con la del ciclo y quedan fuera.
        DB::statement("CREATE UNIQUE INDEX invoices_one_per_period ON invoices (subscription_id, period_start) WHERE billing_reason IN ('subscription_create','subscription_cycle')");

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 32);                             // subscription|proration_credit|proration_charge
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_amount_minor');                // con signo: un crédito es negativo
            $table->bigInteger('amount_minor');                     // quantity * unit_amount_minor
            $table->char('currency', 3);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('invoice_id');
        });

        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_amount_consistent CHECK (amount_minor = quantity * unit_amount_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};

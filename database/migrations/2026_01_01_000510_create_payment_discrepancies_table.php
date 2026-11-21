<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de lo que la pasarela dice y NO cuadra con lo local (cobro sin reflejo, duplicado, reembolso o disputa
        // externos...). Es la bandeja de revisión humana de `payments:reconcile` y de los webhooks (docs/billing.md §Conciliación).
        Schema::create('payment_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('kind', 40);                               // succeeded_without_local | duplicate_charge | ...
            $table->string('gateway', 32);
            $table->string('reference', 191);                         // id de la pasarela que originó el caso
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->jsonb('details')->nullable();
            $table->string('status', 16)->default('open');           // open | resolved
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Un mismo caso se registra una sola vez aunque el webhook o el comando se repitan.
            $table->unique(['gateway', 'reference', 'kind']);
            $table->index(['status', 'detected_at']);
            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE payment_discrepancies ADD CONSTRAINT payment_discrepancies_status_valid CHECK (status IN ('open','resolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_discrepancies');
    }
};

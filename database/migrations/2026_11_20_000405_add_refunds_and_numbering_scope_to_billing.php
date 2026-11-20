<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reembolso automático de un cobro cuya factura quedó anulada (docs/billing.md §Reembolsos).
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('processed_at');
            $table->string('refund_id')->nullable()->after('refunded_at');
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_valid');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','succeeded','failed','refunded'))");

        // Alcance de la numeración con el que se emitió cada factura: cambiarlo con facturas emitidas repetiría números.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number_scope', 8)->default('global')->after('number');
            $table->index('number_scope');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['number_scope']);
            $table->dropColumn('number_scope');
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_valid');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending','succeeded','failed'))");

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refunded_at', 'refund_id']);
        });
    }
};

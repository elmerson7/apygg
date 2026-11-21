<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('plan_price_id')->constrained('plan_prices')->restrictOnDelete();
            $table->string('plan_code', 64);                      // copia congelada: el plan puede renombrarse o retirarse
            $table->string('status', 16);                         // trialing|active|past_due|canceled|expired|incomplete
            $table->string('interval', 16);                       // congelado al suscribirse
            $table->char('currency', 3);
            $table->bigInteger('unit_amount_minor');              // precio congelado: cambiar el catálogo no lo altera
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('billing_anchor_at');               // día/hora de cobro (evita la deriva de fin de mes)
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();         // cuándo se pidió la baja
            $table->timestamp('ended_at')->nullable();            // cuándo dejó de estar viva
            $table->foreignId('scheduled_plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete(); // bajada al final del periodo
            $table->unsignedSmallInteger('renewal_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_valid CHECK (status IN ('trialing','active','past_due','canceled','expired','incomplete'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_amount_nonneg CHECK (unit_amount_minor >= 0)');
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_order CHECK (current_period_end > current_period_start)');

        // Una sola suscripción VIVA por tenant: lo garantiza la BD, no solo el servicio (dos altas a la vez).
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_live_per_tenant ON subscriptions (tenant_id) WHERE status IN ('trialing','active','past_due','incomplete') AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

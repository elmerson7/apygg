<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de la plataforma (sin tenant_id): se declara en config/billing.php y se sincroniza con
        // `billing:sync-plans`. Los límites siguen en config/plans.php (misma clave `code`).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('interval', 16);                // month | year
            $table->bigInteger('unit_amount_minor');       // unidad menor de la moneda (docs/money.md)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['plan_id', 'currency', 'interval']);
        });

        DB::statement("ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_amount_nonneg CHECK (unit_amount_minor >= 0)');
        DB::statement("ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_interval_valid CHECK (interval IN ('month', 'year'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};

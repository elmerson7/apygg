<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de avisos de facturación ya enviados (docs/billing.md §Avisos). El UNIQUE es el reclamo atómico:
        // un aviso se envía una sola vez por (suscripción, tipo, clave de periodo/intento), aunque el comando corra a la vez.
        Schema::create('billing_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('period_key', 128);
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamps();
            $table->softDeletes();   // base `Model`; el reclamo borrado a mano sigue contando para el UNIQUE

            $table->unique(['subscription_id', 'type', 'period_key']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notices');
    }
};

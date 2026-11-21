<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cliente y método de pago por defecto del tenant en la pasarela. NUNCA se guarda el dato de tarjeta:
        // solo ids de la pasarela y marca/últimos 4 para mostrar.
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_customer_id')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->string('payment_method_brand', 32)->nullable();
            $table->string('payment_method_last4', 4)->nullable();
            $table->string('billing_email')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Los webhooks y la conciliación buscan al cliente por el id que da la pasarela.
        DB::statement('CREATE INDEX billing_accounts_gateway_customer_idx ON billing_accounts (gateway, gateway_customer_id) WHERE gateway_customer_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_accounts');
    }
};

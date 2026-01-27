<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('webhook_id');
                $table->string('event_type'); // Tipo de evento (ej: 'user.created')
                $table->json('payload'); // Payload enviado
                $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
                $table->integer('response_code')->nullable(); // Código HTTP de respuesta
                $table->text('response_body')->nullable(); // Cuerpo de respuesta
                $table->text('error_message')->nullable(); // Mensaje de error si falló
                $table->integer('attempts')->default(0); // Número de intentos
                $table->timestamp('delivered_at')->nullable(); // Fecha de entrega exitosa
                $table->timestamp('failed_at')->nullable(); // Fecha de fallo final
                $table->timestamps();

                $table->foreign('webhook_id')->references('id')->on('webhooks')->onDelete('cascade');

                $table->index('webhook_id');
                $table->index('event_type');
                $table->index('status');
                $table->index('created_at');
                $table->index(['webhook_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};

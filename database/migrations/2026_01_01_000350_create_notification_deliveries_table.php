<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas de notificaciones (una fila por notificación, usuario y canal). El UNIQUE es el reclamo
 * idempotente: dos jobs (o un reintento) no pueden enviar la misma entrega dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable();   // se registra, sin FK (alto volumen, como los logs)
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');      // pending | sending | sent | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('skipped_reason', 32)->nullable();      // opt_out | quiet_hours | no_destination | ...
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id', 'channel']);
            $table->index(['status', 'claimed_at']);
            $table->index('created_at');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};

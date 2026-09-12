<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial transversal de notificaciones.
 *
 * NotificationService ya escribía aquí, pero la tabla y el modelo no existían
 * y las llamadas quedaban en nada por una comprobación `class_exists`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_history', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);                 // mail, sms, push, database
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('sent'); // sent, queued, failed
            $table->text('error_message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['recipient', 'sent_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_history');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial viejo (`NotificationHistory`, un stub sin tenant ni usuario) lo sustituye
 * `notification_deliveries`. Ver docs/notifications.md y docs/upgrading.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notification_history');
    }

    public function down(): void
    {
        Schema::create('notification_history', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('sent');
            $table->text('error_message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['recipient', 'sent_at']);
            $table->index('sent_at');
        });
    }
};

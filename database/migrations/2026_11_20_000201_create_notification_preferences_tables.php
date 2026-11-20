<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de notificación por usuario: opt-out por tipo y canal, y horas de silencio.
 * Son de la persona (valen en todos sus tenants): sin `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_start', 5)->nullable();   // HH:MM en la zona horaria del usuario
            $table->string('quiet_end', 5)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_preferences');
    }
};

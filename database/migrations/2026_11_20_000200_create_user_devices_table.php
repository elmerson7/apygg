<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos push de un usuario. Sustituye a `device_tokens` (creada sin modelo ni uso).
 * Un dispositivo es de la PERSONA, no de un tenant: sin `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();          // un token solo puede estar activo en un usuario
            $table->string('platform', 16);                   // ios | android | web
            $table->string('device_id', 191)->nullable();     // id propio de la app (opcional)
            $table->string('name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('last_seen_at');
        });

        Schema::dropIfExists('device_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 20)->default('android');
            $table->string('device_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index('token');
        });
    }
};

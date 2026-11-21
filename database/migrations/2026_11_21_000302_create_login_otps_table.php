<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de un solo uso para entrar sin contraseña (módulo `otp-login`, docs/otp-login.md).
 *
 * El código va hasheado (`code_hash`), nunca en claro; el destino se guarda como HMAC (`destination_hash`) para
 * acotar intentos sin conservar el correo/teléfono. Solo se crea fila para destinos de usuarios que existen y
 * pueden entrar; la API responde igual exista o no. Un código vale hasta `expires_at`, `max_attempts` intentos y un solo uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('destination_hash', 64);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['destination_hash', 'channel', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otps');
    }
};

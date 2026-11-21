<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de usuario (una por login): dispositivo, IP, último uso y estado.
 *
 * `refresh_jti` es el identificador del ÚNICO refresh token vigente de la sesión: la
 * rotación lo cambia con un UPDATE condicional (compare-and-swap) y guarda el anterior en
 * `previous_refresh_jti` (+ `refresh_rotated_at`) para tolerar una carrera legítima durante unos
 * segundos; un refresh con otro `jti`, o el anterior fuera de esa gracia, es reutilización (robo)
 * y revoca la sesión. No guarda tokens, solo su id.
 * Docs: docs/sessions-and-2fa.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device', 120)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('refresh_jti', 64)->nullable();
            $table->string('previous_refresh_jti', 64)->nullable();
            $table->timestamp('refresh_rotated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};

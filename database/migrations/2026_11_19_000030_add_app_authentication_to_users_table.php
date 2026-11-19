<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA por app (TOTP) del back-office (Filament).
 *
 * Ambas columnas se guardan cifradas (casts `encrypted` del paquete), de ahí `text`.
 * Aditiva y nullable: los usuarios existentes quedan sin MFA y el panel les pedirá
 * configurarlo al entrar. Si quitas el back-office (docs/backoffice.md) puedes
 * dejarlas: son inertes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};

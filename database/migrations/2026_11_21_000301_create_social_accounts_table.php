<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidades externas (Google, GitHub...) vinculadas a un usuario (módulo `social-login`, docs/social-login.md).
 *
 * La identidad es global como el usuario (sin `tenant_id`). `provider_user_id` (el `sub` del proveedor) es
 * lo que identifica a la persona: el email puede cambiar y por sí solo NO vincula nada. Un `provider_user_id` solo
 * puede estar en un usuario y un usuario tiene una sola cuenta por proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id', 191);
            $table->string('email', 255)->nullable();
            $table->boolean('email_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

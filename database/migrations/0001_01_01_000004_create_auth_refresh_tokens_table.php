<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('user_id', 26)->index();
            $table->string('jti')->unique(); // JWT ID
            $table->string('token_hash', 64); // SHA256
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at', 0)->index();
            $table->timestamp('revoked_at', 0)->nullable()->index();
            $table->string('replaced_by_id', 26)->nullable(); // Self reference para rotación
            $table->timestamp('created_at', 0);
            
            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            // Self-reference foreign key se agregará después de crear la tabla
            
            // Índices adicionales
            $table->index(['user_id', 'revoked_at']);
            $table->index(['expires_at', 'revoked_at']);
        });

        // Agregar self-reference foreign key después de crear la tabla
        Schema::table('auth_refresh_tokens', function (Blueprint $table) {
            $table->foreign('replaced_by_id')->references('id')->on('auth_refresh_tokens')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_refresh_tokens');
    }
};

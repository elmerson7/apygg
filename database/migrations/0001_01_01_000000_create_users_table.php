<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // PK ULID
            $table->string('id', 26)->primary();
            
            // Campos básicos
            $table->string('email')->unique()->index();
            $table->string('password'); // Argon2ID configurado en config/hashing.php
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable()->index();
            
            // Email verification
            $table->timestamp('email_verified_at', 0)->nullable();
            
            // 2FA campos
            $table->boolean('two_factor_enabled')->default(false);
            $table->enum('two_factor_channel', ['sms', 'app'])->nullable();
            $table->string('two_factor_secret')->nullable();
            
            // Tracking campos
            $table->timestamp('last_login_at', 0)->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            
            // Timestamps UTC con precisión 0
            $table->timestamp('created_at', 0);
            $table->timestamp('updated_at', 0);
            
            // Soft deletes
            $table->timestamp('deleted_at', 0)->nullable();
            
            // Índices adicionales
            $table->index('deleted_at');
            $table->index(['email_verified_at', 'deleted_at']);
        });

        // Mantener password_reset_tokens para recuperación por email
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at', 0)->nullable();
        });

        // NO crear sessions (stateless API)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

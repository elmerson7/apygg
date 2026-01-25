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
        Schema::create('logs_security', function (Blueprint $table) {
            $table->id();
            $table->uuid('trace_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->enum('event_type', [
                'login_success',
                'login_failure',
                'permission_denied',
                'suspicious_activity',
                'password_changed',
                'token_revoked',
                'account_locked',
                'account_unlocked'
            ]);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('trace_id');
            $table->index('user_id');
            $table->index('event_type');
            $table->index('created_at');
            $table->index(['event_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_security');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('user_id', 26);
            $table->string('provider', 50); // google, facebook, etc.
            $table->string('provider_user_id')->index();
            $table->string('email')->nullable();
            $table->jsonb('raw')->nullable(); // Datos raw del provider
            $table->timestamp('created_at', 0);
            
            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Índices
            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_webhook_sources', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('name');
            $table->string('provider', 100); // stripe, github, etc.
            $table->string('secret'); // Para verificar firmas
            $table->boolean('active')->default(true);
            $table->timestamp('created_at', 0);
            $table->timestamp('updated_at', 0);
            
            // Índices
            $table->index(['provider', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhook_sources');
    }
};

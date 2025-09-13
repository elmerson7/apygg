<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('name');
            $table->string('url');
            $table->string('secret'); // Para firmar webhooks
            $table->jsonb('events'); // Array de eventos suscritos
            $table->boolean('active')->default(true);
            $table->timestamp('created_at', 0);
            $table->timestamp('updated_at', 0);
            
            // Índices
            $table->index(['active', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};

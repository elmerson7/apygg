<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_webhook_events', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('source_id', 26);
            $table->string('external_id')->unique(); // ID del proveedor
            $table->string('event', 100);
            $table->jsonb('payload');
            $table->string('signature');
            $table->string('idempotency_key')->nullable()->index();
            $table->timestamp('received_at', 0);
            $table->timestamp('processed_at', 0)->nullable();
            $table->enum('status', ['received', 'processed', 'failed'])->default('received');
            
            // Foreign key
            $table->foreign('source_id')->references('id')->on('incoming_webhook_sources')->onDelete('cascade');
            
            // Índices
            $table->index(['source_id', 'received_at']);
            $table->index(['status', 'processed_at']);
            $table->index(['event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhook_events');
    }
};

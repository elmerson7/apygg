<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('endpoint_id', 26);
            $table->string('event', 100);
            $table->jsonb('payload');
            $table->string('signature');
            $table->smallInteger('status_code')->nullable()->index();
            $table->smallInteger('attempt')->default(1);
            $table->smallInteger('max_attempts')->default(3);
            $table->text('error')->nullable();
            $table->text('response_body')->nullable(); // Truncado
            $table->timestamp('next_retry_at', 0)->nullable();
            $table->timestamp('delivered_at', 0)->nullable();
            $table->timestamp('created_at', 0);
            
            // Foreign key
            $table->foreign('endpoint_id')->references('id')->on('webhook_endpoints')->onDelete('cascade');
            
            // Índices
            $table->index(['endpoint_id', 'created_at']);
            $table->index(['status_code', 'next_retry_at']);
            $table->index(['delivered_at', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};

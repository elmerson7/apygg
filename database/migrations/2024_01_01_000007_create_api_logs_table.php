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
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('trace_id');
            $table->uuid('user_id')->nullable();
            $table->string('request_method', 10);
            $table->string('request_path', 500);
            $table->json('request_query')->nullable();
            $table->json('request_body')->nullable();
            $table->json('request_headers')->nullable();
            $table->integer('response_status');
            $table->json('response_body')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('trace_id');
            $table->index('user_id');
            $table->index('created_at');
            $table->index(['request_method', 'request_path']);
            $table->index('response_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};

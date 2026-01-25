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
        if (! Schema::hasTable('webhooks')) {
            Schema::create('webhooks', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('user_id')->nullable();
                $table->string('name');
                $table->string('url');
                $table->string('secret')->nullable(); // Secret para firmar payloads
                $table->json('events')->nullable(); // Eventos que activan el webhook
                $table->enum('status', ['active', 'inactive', 'paused'])->default('active');
                $table->integer('timeout')->default(30); // Timeout en segundos
                $table->integer('max_retries')->default(3);
                $table->timestamp('last_triggered_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->integer('success_count')->default(0);
                $table->integer('failure_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

                $table->index('user_id');
                $table->index('status');
                $table->index('url');
                $table->index('created_at');
                $table->index('last_triggered_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};

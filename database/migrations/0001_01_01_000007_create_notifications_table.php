<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->string('notifiable_id', 26); // ULID support
            $table->jsonb('data');
            $table->timestamp('read_at', 0)->nullable();
            $table->timestamp('created_at', 0);
            $table->timestamp('updated_at', 0);

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

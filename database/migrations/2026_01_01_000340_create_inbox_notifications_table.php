<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de notificaciones in-app (canal `database`). No usa la tabla `notifications` de Laravel:
 * su `id` no es uuid y no lleva tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('notification_id')->nullable();      // id de la Notification que la originó
            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'read_at']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index('created_at');
            $table->unique(['notification_id', 'user_id']);   // reintento del job: no duplica el aviso
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');
    }
};

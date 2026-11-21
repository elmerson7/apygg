<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de borrado de cuenta (derecho de supresión; docs/privacy.md). Una sola `pending` por usuario
 * (índice único parcial): pedirlo dos veces es idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');   // pending | processing | completed | cancelled | failed
            $table->timestamp('requested_at');
            $table->timestamp('scheduled_for');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index('user_id');
        });

        DB::statement("CREATE UNIQUE INDEX account_deletion_requests_one_open ON account_deletion_requests (user_id) WHERE status IN ('pending', 'processing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};

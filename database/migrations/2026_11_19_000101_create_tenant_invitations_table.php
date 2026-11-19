<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');                       // siempre en minúsculas
            $table->string('role');
            $table->char('token_hash', 64)->unique();      // sha256 del token; el token en claro solo va en el correo
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'email']);
        });

        // Una sola invitación PENDIENTE por (tenant, email): también frena la carrera de dos altas a la vez.
        DB::statement('CREATE UNIQUE INDEX tenant_invitations_pending_unique ON tenant_invitations (tenant_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};

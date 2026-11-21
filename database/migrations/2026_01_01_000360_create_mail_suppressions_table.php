<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global por diseño: un buzón que rebotó o se quejó lo hizo para TODA la plataforma (no lleva tenant_id).
        Schema::create('mail_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();      // normalizado (minúsculas, sin espacios): el duplicado lo decide la restricción
            $table->string('reason', 16);                // bounce | complaint | manual
            $table->string('source', 64);                // resend | admin | ...
            $table->jsonb('details')->nullable();        // tipo de rebote, id del evento (sin el payload completo)
            $table->timestamp('suppressed_at');
            $table->timestamps();

            $table->index(['reason', 'suppressed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_suppressions');
    }
};

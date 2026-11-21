<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de identidades bloqueadas (correo, documento o usuario).
 *
 * Distinta del bloqueo por IP de config/security.php: esta actúa sobre la
 * persona, que puede volver desde otra IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('identity_document')->nullable();
            $table->string('name')->nullable();
            $table->text('reason');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('identity_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};

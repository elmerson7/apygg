<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Eliminar tabla con CASCADE para eliminar índices asociados
        DB::statement('DROP TABLE IF EXISTS settings CASCADE');
        
        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json, array
            $table->string('group')->nullable(); // general, system, api, etc.
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false); // Si puede ser accedido públicamente
            $table->boolean('is_encrypted')->default(false); // Si el valor está encriptado
            $table->timestamps();
            $table->softDeletes();

            $table->index('key');
            $table->index('group');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

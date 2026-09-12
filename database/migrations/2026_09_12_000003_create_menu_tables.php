<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menú dinámico del panel.
 *
 * - menu_items     → ítems, con su permiso o su jerarquía
 * - menu_item_role → visibilidad explícita por rol, para ítems sin permiso
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('route')->nullable();
            $table->string('icon')->nullable();
            // Permiso que da visibilidad. Es el modo recomendado: así el menú
            // queda derivado de lo que el usuario realmente puede hacer.
            $table->string('permission')->nullable();
            $table->string('parent_key')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index('parent_key');
        });

        Schema::create('menu_item_role', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['menu_item_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_role');
        Schema::dropIfExists('menu_items');
    }
};

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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('status')->default('active');
            $table->foreignId('parent_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('plan', 50)->nullable(); // NULL = plan por defecto (config/plans.php)
            $table->jsonb('settings')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // Preferencias regionales con herencia: NULL = heredar de la instalación (docs/i18n.md)
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

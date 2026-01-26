<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('logs_error');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No recreamos la tabla ya que fue eliminada intencionalmente
        // Si necesitas restaurarla, usa la migración original: 2024_01_01_000008_create_error_logs_table.php
    }
};

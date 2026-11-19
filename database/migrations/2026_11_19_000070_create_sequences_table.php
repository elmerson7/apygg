<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores atómicos para numeraciones (facturas, pedidos, tickets...). Ver docs/sequences.md.
 *
 * Una fila por (tenant, nombre, periodo). `tenant_id` NULL = secuencia global (sin multiempresa o de
 * plataforma); `period` es '' salvo en secuencias con reinicio anual (p. ej. '2026'). La unicidad usa
 * NULLS NOT DISTINCT (PostgreSQL 15+) para que dos filas globales con el mismo nombre también choquen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('period', 10)->default('');
            $table->string('prefix', 40)->nullable();
            $table->unsignedSmallInteger('padding')->default(0);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedBigInteger('max_value')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE sequences ADD CONSTRAINT sequences_scope_name_period_unique UNIQUE NULLS NOT DISTINCT (tenant_id, name, period)');
        DB::statement('ALTER TABLE sequences ADD CONSTRAINT sequences_next_value_positive CHECK (next_value >= 1)');
        DB::statement('ALTER TABLE sequences ADD CONSTRAINT sequences_padding_range CHECK (padding BETWEEN 0 AND 20)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Impersonación (docs/impersonation.md): cuando soporte actúa como otro usuario, `actor_id` es el usuario
 * suplantado y `impersonator_id` quien lo hace de verdad. Nullable: la mayoría de las entradas no la usan.
 *
 * `audit_logs` crece mucho: el índice se crea CONCURRENTLY (sin bloquear escrituras), así que la migración
 * no corre dentro de una transacción (docs/migrations.md §9).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('impersonator_id', 64)->nullable()->after('actor_id');
        });

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_logs_impersonator_id_index ON audit_logs (impersonator_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS audit_logs_impersonator_id_index');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('impersonator_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une cada registro de actividad con la petición que lo causó (`trace_id` = `request_id` de la respuesta
 * y cabecera X-Request-ID), igual que `logs_api` y `logs_security`. Nula en consola y colas.
 */
return new class extends Migration
{
    public $withinTransaction = false; // CREATE INDEX CONCURRENTLY no cabe en una transacción

    public function up(): void
    {
        Schema::table('logs_activity', function (Blueprint $table) {
            $table->string('trace_id', 64)->nullable();
        });

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS logs_activity_trace_id_index ON logs_activity (trace_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS logs_activity_trace_id_index');

        Schema::table('logs_activity', function (Blueprint $table) {
            $table->dropColumn('trace_id');
        });
    }
};

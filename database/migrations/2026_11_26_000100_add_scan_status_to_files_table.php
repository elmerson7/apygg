<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Escaneo antivirus de las subidas (docs/files.md, "Escaneo antivirus"): estado del escaneo, cuándo se hizo y su
 * resultado (firma detectada o motivo del fallo). Los archivos ya guardados quedan `skipped` (subidos sin escaneo),
 * que es como estaban. El índice parcial solo cubre lo que `files:scan` vuelve a encolar.
 */
return new class extends Migration
{
    public $withinTransaction = false; // CREATE INDEX CONCURRENTLY no cabe en una transacción

    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('scan_status', 16)->default('skipped');
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result', 255)->nullable();
        });

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS files_scan_status_pending_index ON files (scan_status) WHERE scan_status IN ('pending', 'failed')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS files_scan_status_pending_index');

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scanned_at', 'scan_result']);
        });
    }
};

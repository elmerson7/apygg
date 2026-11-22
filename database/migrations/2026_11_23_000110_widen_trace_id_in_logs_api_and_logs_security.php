<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `trace_id` pasa de uuid a varchar(64) en `logs_api` y `logs_security`.
 *
 * TraceIdMiddleware respeta cualquier X-Request-ID de 1 a 64 caracteres `A-Za-z0-9._:-`
 * (docs/api-response-structure.md), pero estas columnas solo aceptaban UUID: con un id de cliente
 * como `req-123` el log no se guardaba y, dentro de una transacción, la abortaba. Ahora es el
 * mismo tipo que `audit_logs.request_id` y `logs_activity.trace_id`.
 *
 * uuid -> varchar reescribe la tabla (bloqueo mientras dura): son logs con retención corta;
 * en una tabla muy grande, purga antes con `logs:clean` (docs/upgrading.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        // migration-lint: ignore change-type -- logs con retención corta; ver docblock
        DB::statement('ALTER TABLE logs_api ALTER COLUMN trace_id TYPE varchar(64) USING trace_id::text');
        // migration-lint: ignore change-type -- logs con retención corta; ver docblock
        DB::statement('ALTER TABLE logs_security ALTER COLUMN trace_id TYPE varchar(64) USING trace_id::text');
    }

    public function down(): void
    {
        $noUuid = "trace_id !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'";

        // Los ids de cliente que no son UUID no caben en el tipo anterior
        DB::statement("UPDATE logs_api SET trace_id = gen_random_uuid()::text WHERE {$noUuid}");
        DB::statement("UPDATE logs_security SET trace_id = NULL WHERE {$noUuid}");
        DB::statement('ALTER TABLE logs_api ALTER COLUMN trace_id TYPE uuid USING trace_id::uuid');
        DB::statement('ALTER TABLE logs_security ALTER COLUMN trace_id TYPE uuid USING trace_id::uuid');
    }
};

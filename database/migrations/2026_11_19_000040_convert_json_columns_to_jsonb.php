<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las migraciones originales crearon varias columnas como `json`. En PostgreSQL
 * lo recomendado es `jsonb` (indexable, comparable, sin reparsear en cada
 * consulta). Esta migración convierte las existentes sin reescribir las
 * migraciones antiguas. Las migraciones nuevas deben usar `$table->jsonb()`.
 *
 * Reescribe las tablas afectadas: en `logs_api` y `logs_activity` con muchos
 * datos, ejecútala en una ventana de mantenimiento.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> tabla => columnas */
    private array $columnas = [
        'users' => ['preferences'],
        'user_profiles' => ['social_links'],
        'api_keys' => ['scopes'],
        'logs_api' => ['request_query', 'request_body', 'request_headers', 'response_body'],
        'logs_activity' => ['old_values', 'new_values'],
        'logs_security' => ['details'],
        'files' => ['metadata'],
        'webhooks' => ['events'],
        'webhook_deliveries' => ['payload'],
        'tenants' => ['settings'],
    ];

    public function up(): void
    {
        $this->convertir('json', 'jsonb');
    }

    public function down(): void
    {
        $this->convertir('jsonb', 'json');
    }

    private function convertir(string $desde, string $hacia): void
    {
        foreach ($this->columnas as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                // Idempotente: solo toca la columna si existe y está en el tipo de origen.
                if (! Schema::hasColumn($tabla, $columna) || Schema::getColumnType($tabla, $columna) !== $desde) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE %s USING "%s"::%s',
                    $tabla, $columna, $hacia, $columna, $hacia
                ));
            }
        }
    }
};

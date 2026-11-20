<?php

/**
 * Linter de migraciones (`php artisan migrations:lint`, docs/migrations.md §9).
 *
 * Detecta en migraciones NUEVAS patrones que bloquean o rompen un despliegue sin parada.
 */
return [
    // Las migraciones con nombre <= a este quedan como base permitida (ya aplicadas en main).
    // No se toca: una migración posterior que necesite saltarse una regla usa la marca inline
    // `// migration-lint: ignore <regla> -- motivo` o la lista `exceptions` de abajo.
    'baseline' => env('MIGRATION_LINT_BASELINE', '2026_11_20_000280_create_user_sessions_table'),

    // Excepciones por archivo: 'nombre_sin_extension' => ['regla', ...] (o ['*'] para todas).
    'exceptions' => [
        // '2026_12_01_000010_ejemplo' => ['rename-column'],
    ],

    // Tablas grandes: un índice sin CREATE INDEX CONCURRENTLY sobre ellas bloquea escrituras.
    'large_tables' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MIGRATION_LINT_LARGE_TABLES',
        'users,api_logs,mail_logs,mail_events,audit_logs,activity_logs,notifications,notification_deliveries,webhook_deliveries,llm_logs,files'
    ))))),

    // Tiempos que aplica `App\Support\Database\SafeMigration` (formato PostgreSQL: '5s', '10min', '0' = sin límite).
    'lock_timeout' => env('MIGRATION_LOCK_TIMEOUT', '5s'),
    'statement_timeout' => env('MIGRATION_STATEMENT_TIMEOUT', '60s'),
];

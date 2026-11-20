<?php

return [

    /*
    | Auditoría de negocio (docs/audit.md). Apagada: ni los modelos `Auditable` ni las acciones escriben nada.
    */
    'enabled' => env('AUDIT_ENABLED', true),

    /*
    | Días que se conserva la auditoría (`audit:prune`, programado a diario). 0 = no borrar nunca.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),

    /*
    | Acciones sensibles que EXIGEN un motivo (`reason`) de quien las ejecuta. Lista vacía = ninguna.
    | Una acción nueva se añade aquí y se registra con `AuditServiceInterface::requireReason()`.
    */
    'reason_required' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'AUDIT_REASON_REQUIRED',
        'user.roles-change,user.block,user.unblock,impersonation.start',
    ))))),

    'reason_max_length' => 500,

    /*
    | Atributos que NUNCA entran en un diff, además de los `$hidden` del modelo y de lo que redacte
    | `App\Helpers\Redactor` (config/logging.php → redact).
    */
    'exclude' => ['updated_at', 'created_at', 'deleted_at', 'remember_token'],

    /*
    | Exportación CSV: filas leídas por lote y tope de filas por descarga.
    */
    'export' => [
        'chunk' => 500,
        'max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 100000),
    ],
];

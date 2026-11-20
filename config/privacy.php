<?php

/*
|--------------------------------------------------------------------------
| Privacidad RGPD/LGPD (docs/privacy.md)
|--------------------------------------------------------------------------
*/

return [

    /*
    | Consentimientos versionados. `required`: claves de documento OBLIGATORIAS (se exige aceptar la versión
    | vigente). `enforce`: activa el middleware global que responde 409 `consent_required`. `except`: patrones de
    | ruta (`Request::is`) que nunca lo exigen (auth, legal, el propio consentimiento y los derechos del usuario).
    */
    'consents' => [
        'required' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRIVACY_CONSENTS_REQUIRED', 'terms,privacy'))))),
        'enforce' => (bool) env('PRIVACY_CONSENTS_ENFORCE', false),
        'except' => [
            'up', 'health', 'health/*', 'auth/*', 'legal', 'legal/*', 'user/consents', 'user/consents/*',
            'user/data-export', 'user/data-export/*', 'user/account-deletion', 'user/account-deletion/*',
            'broadcasting/auth',
        ],
    ],

    /*
    | Exportación de datos personales (portabilidad).
    */
    'export' => [
        'enabled' => (bool) env('PRIVACY_EXPORT_ENABLED', true),
        'disk' => env('PRIVACY_EXPORT_DISK', env('FILE_DISK_PRIVATE', 'local')),
        'directory' => 'privacy-exports',
        'ttl_hours' => (int) env('PRIVACY_EXPORT_TTL_HOURS', 72),
        'min_interval_hours' => (int) env('PRIVACY_EXPORT_MIN_INTERVAL_HOURS', 24),
        'max_audit_rows' => (int) env('PRIVACY_EXPORT_MAX_AUDIT_ROWS', 10000),
        'queue' => env('PRIVACY_QUEUE', 'default'),
    ],

    /*
    | Borrado de cuenta: periodo de gracia (días; 0 = a la siguiente pasada del programador) y qué hacer con los
    | archivos que subió: `delete` (borra fila y objeto) o `detach` (los conserva en el tenant, sin autor).
    */
    'deletion' => [
        'grace_days' => (int) env('PRIVACY_DELETION_GRACE_DAYS', 14),
        'require_password' => true,
    ],

    'erase' => [
        'files' => env('PRIVACY_ERASE_FILES', 'delete'),
    ],

    /*
    | Retención de lo que genera ESTE módulo (`privacy:purge`). El resto de purgados ya existen y no se duplican:
    | ver la tabla de docs/privacy.md. 0 = no purgar.
    */
    'retention' => [
        'deletion_requests_days' => (int) env('PRIVACY_DELETION_REQUESTS_RETENTION_DAYS', 365),
        'export_records_days' => (int) env('PRIVACY_EXPORT_RECORDS_RETENTION_DAYS', 90),
    ],
];

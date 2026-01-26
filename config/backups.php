<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuración de Backups
    |--------------------------------------------------------------------------
    |
    | Configuración del sistema de backups automáticos y manuales.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Directorio de Backups Local
    |--------------------------------------------------------------------------
    |
    | Directorio donde se almacenan los backups localmente antes de subirlos
    | a almacenamiento remoto.
    |
    */

    'local_path' => storage_path('app/backups'),

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento Remoto
    |--------------------------------------------------------------------------
    |
    | Configuración para subir backups a almacenamiento remoto (S3, SFTP, etc.)
    |
    */

    'remote' => [
        'enabled' => env('BACKUP_REMOTE_ENABLED', true), // Habilitado por defecto
        'disk' => env('BACKUP_REMOTE_DISK', 's3'), // 's3', 'local', 'sftp'
        'path' => env('BACKUP_REMOTE_PATH', 'backups'), // Ruta en el disco remoto
    ],

    /*
    |--------------------------------------------------------------------------
    | Compresión
    |--------------------------------------------------------------------------
    |
    | Configuración de compresión de backups.
    |
    */

    'compression' => [
        'enabled' => env('BACKUP_COMPRESSION_ENABLED', true),
        'format' => env('BACKUP_COMPRESSION_FORMAT', 'gzip'), // 'gzip', 'bzip2'
    ],

    /*
    |--------------------------------------------------------------------------
    | Retención de Backups
    |--------------------------------------------------------------------------
    |
    | Política de retención de backups:
    - daily: días para mantener backups diarios
    - weekly: días para mantener backups semanales
    - monthly: días para mantener backups mensuales
    |
    */

    'retention' => [
        'daily' => env('BACKUP_RETENTION_DAILY', 7), // 7 días
        'weekly' => env('BACKUP_RETENTION_WEEKLY', 30), // 30 días
        'monthly' => env('BACKUP_RETENTION_MONTHLY', 90), // 90 días
    ],

    /*
    |--------------------------------------------------------------------------
    | Base de Datos
    |--------------------------------------------------------------------------
    |
    | Configuración para backups de base de datos.
    |
    */

    'database' => [
        'enabled' => env('BACKUP_DATABASE_ENABLED', true),
        'connection' => env('DB_CONNECTION', 'pgsql'),
        'include_data' => true, // Incluir datos en el backup
        'include_schema' => true, // Incluir esquema en el backup
    ],

    /*
    |--------------------------------------------------------------------------
    | Archivos
    |--------------------------------------------------------------------------
    |
    | Configuración para backups de archivos.
    |
    */

    'files' => [
        'enabled' => env('BACKUP_FILES_ENABLED', false),
        'paths' => [
            storage_path('app/public'),
            // Agregar más rutas según necesidad
        ],
        'exclude' => [
            '*.log',
            '*.tmp',
            '*.cache',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notificaciones
    |--------------------------------------------------------------------------
    |
    | Configuración de notificaciones de backups.
    |
    */

    'notifications' => [
        'enabled' => env('BACKUP_NOTIFICATIONS_ENABLED', true),
        'on_success' => env('BACKUP_NOTIFY_ON_SUCCESS', false),
        'on_failure' => env('BACKUP_NOTIFY_ON_FAILURE', true),
        'channels' => ['log'], // 'log', 'mail', 'slack', etc.
    ],

];

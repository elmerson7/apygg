<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Límites de Tamaño por Tipo de Archivo
    |--------------------------------------------------------------------------
    |
    | Define el tamaño máximo permitido (en bytes) para cada tipo de archivo.
    | Estos límites se aplican antes de la validación de upload.
    |
    */

    'max_sizes' => [
        'image' => env('FILE_MAX_SIZE_IMAGE', 5 * 1024 * 1024), // 5MB por defecto
        'document' => env('FILE_MAX_SIZE_DOCUMENT', 10 * 1024 * 1024), // 10MB por defecto
        'video' => env('FILE_MAX_SIZE_VIDEO', 50 * 1024 * 1024), // 50MB por defecto
        'audio' => env('FILE_MAX_SIZE_AUDIO', 10 * 1024 * 1024), // 10MB por defecto
        'default' => env('FILE_MAX_SIZE_DEFAULT', 10 * 1024 * 1024), // 10MB por defecto
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos MIME Permitidos
    |--------------------------------------------------------------------------
    |
    | Lista de tipos MIME permitidos agrupados por categoría.
    | Se valida contra esta lista durante el upload.
    |
    */

    'allowed_mimes' => [
        'image' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
            'text/plain',
            'text/csv',
        ],
        'video' => [
            'video/mp4',
            'video/mpeg',
            'video/quicktime',
            'video/x-msvideo',
            'video/webm',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/webm',
        ],
        'archive' => [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-tar',
            'application/gzip',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensiones Permitidas
    |--------------------------------------------------------------------------
    |
    | Lista de extensiones de archivo permitidas agrupadas por categoría.
    | Se valida contra esta lista además del tipo MIME.
    |
    */

    'allowed_extensions' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
        'video' => ['mp4', 'mpeg', 'mov', 'avi', 'webm'],
        'audio' => ['mp3', 'wav', 'ogg', 'webm'],
        'archive' => ['zip', 'rar', 'tar', 'gz'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Políticas de Retención
    |--------------------------------------------------------------------------
    |
    | Define cuánto tiempo se mantienen los archivos antes de eliminarlos.
    | Valores en días. null = sin expiración automática.
    |
    */

    'retention_policies' => [
        'temporary' => env('FILE_RETENTION_TEMPORARY', 7), // 7 días para archivos temporales
        'user_upload' => env('FILE_RETENTION_USER_UPLOAD', null), // Sin expiración por defecto
        'avatar' => env('FILE_RETENTION_AVATAR', null), // Sin expiración
        'document' => env('FILE_RETENTION_DOCUMENT', 365), // 1 año
        'backup' => env('FILE_RETENTION_BACKUP', 90), // 90 días
    ],

    /*
    |--------------------------------------------------------------------------
    | Rutas de Almacenamiento por Tipo
    |--------------------------------------------------------------------------
    |
    | Define las rutas donde se almacenan los archivos según su tipo.
    |
    */

    'storage_paths' => [
        'avatar' => 'avatars',
        'document' => 'documents',
        'image' => 'images',
        'video' => 'videos',
        'audio' => 'audio',
        'temporary' => 'temp',
        'default' => 'uploads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Procesamiento de Imágenes
    |--------------------------------------------------------------------------
    |
    | Configuración para procesamiento de imágenes (redimensionamiento, etc.)
    |
    */

    'image_processing' => [
        'enabled' => env('FILE_IMAGE_PROCESSING_ENABLED', true),
        'max_width' => env('FILE_IMAGE_MAX_WIDTH', 2048),
        'max_height' => env('FILE_IMAGE_MAX_HEIGHT', 2048),
        'quality' => env('FILE_IMAGE_QUALITY', 85), // 0-100
        'formats' => ['jpg', 'png', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Disco por Tipo
    |--------------------------------------------------------------------------
    |
    | Define qué disco de almacenamiento usar según el tipo de archivo.
    | Opciones: 'local', 'public', 's3'
    |
    */

    'disk_by_type' => [
        'avatar' => env('FILE_DISK_AVATAR', 'public'),
        'document' => env('FILE_DISK_DOCUMENT', 'local'),
        'image' => env('FILE_DISK_IMAGE', 'public'),
        'video' => env('FILE_DISK_VIDEO', 'local'),
        'audio' => env('FILE_DISK_AUDIO', 'local'),
        'default' => env('FILE_DISK_DEFAULT', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validación de Virus
    |--------------------------------------------------------------------------
    |
    | Configuración para escaneo de virus (requiere servicio externo).
    |
    */

    'virus_scanning' => [
        'enabled' => env('FILE_VIRUS_SCAN_ENABLED', false),
        'service' => env('FILE_VIRUS_SCAN_SERVICE', 'clamav'), // clamav, cloudmersive, etc.
    ],

];

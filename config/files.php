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
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
        'video' => ['mp4', 'mpeg', 'mov', 'avi', 'webm'],
        'audio' => ['mp3', 'wav', 'ogg', 'webm'],
        'archive' => ['zip', 'rar', 'tar', 'gz'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos y Extensiones Prohibidos (lista negra)
    |--------------------------------------------------------------------------
    |
    | Contenido que el navegador ejecuta (XSS almacenado) o el servidor
    | interpreta. Se rechaza SIEMPRE, aunque alguien lo añada por error a las
    | listas permitidas. El tipo se detecta por el CONTENIDO del archivo, no por
    | lo que declara el cliente. No elimines SVG de aquí: un SVG puede llevar
    | <script> y se ejecutaría en el origen de la API.
    |
    */

    'blocked_mimes' => [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'application/x-httpd-php',
        'text/x-php',
    ],

    'blocked_extensions' => [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'xsl',
        'js', 'mjs', 'php', 'phtml', 'phar', 'pht', 'htaccess',
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
    | Discos de Almacenamiento (público / privado)
    |--------------------------------------------------------------------------
    |
    | El disco depende de `is_public`, no de la categoría:
    |
    |  - is_public = true  -> disco `public`: URL estable servida sin autenticación.
    |  - is_public = false -> disco `private`: NUNCA tiene URL pública. Se descarga por
    |    GET /files/{id}/download (dueño o permiso files.manage) o con una URL temporal
    |    firmada (GET /files/{id}/temporary-url).
    |
    | Valores: `public` / `local` (disco local), `s3` / `s3_private` (S3 o el emulador local).
    | Con S3 usa un bucket PRIVADO para `private` (AWS_PRIVATE_BUCKET): la
    | visibilidad por objeto no se respeta igual en todos los proveedores.
    |
    */

    'disks' => [
        'public' => env('FILE_DISK_PUBLIC', 'public'),
        'private' => env('FILE_DISK_PRIVATE', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prefijo por entorno
    |--------------------------------------------------------------------------
    |
    | Primer segmento de toda clave nueva en disco (`<prefijo>/tenants/<id>/<ruta>`), para que
    | producción, staging y local puedan compartir un bucket sin pisarse. Por defecto `APP_ENV`;
    | vacío = sin prefijo. Un solo segmento (letras, números, `.`, `_`, `-`). Los archivos ya
    | guardados conservan su `path` en BD: cambiar el prefijo NO los mueve ni los rompe.
    |
    */
    'prefix' => env('FILES_PREFIX', env('APP_ENV')),

    /*
    |--------------------------------------------------------------------------
    | Subida directa presignada (POST /files/uploads)
    |--------------------------------------------------------------------------
    |
    | `intent_minutes`: validez de la URL PUT y de la intención (reserva de cuota incluida).
    | `retention_days`: cuánto se conservan las intenciones completadas o abortadas antes de borrarse.
    |
    */
    'direct_upload' => [
        'intent_minutes' => (int) env('FILE_DIRECT_UPLOAD_MINUTES', 30),
        'retention_days' => (int) env('FILE_DIRECT_UPLOAD_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subida por partes (S3 multipart) para archivos grandes
    |--------------------------------------------------------------------------
    |
    | `POST /files/uploads/multipart`: el cliente sube cada parte con su URL prefirmada y completa al final.
    | Reutiliza la intención de subida directa (reserva de cuota, caducidad, purga). Solo discos S3.
    | `part_size_mb` es el tamaño base (mínimo 5, límite de S3); si el archivo necesitaría más de `max_parts`
    | (tope de S3: 10000) el servidor agranda la parte. `max_size_mb` sustituye aquí al tope por tipo de
    | `max_sizes`; el `max_file_mb` del plan sigue mandando.
    |
    */
    'multipart' => [
        'enabled' => (bool) env('FILE_MULTIPART_ENABLED', true),
        'part_size_mb' => (int) env('FILE_MULTIPART_PART_MB', 16),
        'max_parts' => (int) env('FILE_MULTIPART_MAX_PARTS', 10000),
        'max_size_mb' => (int) env('FILE_MULTIPART_MAX_MB', 5120),
        'intent_minutes' => (int) env('FILE_MULTIPART_MINUTES', 1440),
        'url_minutes' => (int) env('FILE_MULTIPART_URL_MINUTES', 60),
        'max_urls_per_request' => (int) env('FILE_MULTIPART_MAX_URLS', 100),
    ],

    /*
    | Minutos de validez de las URLs temporales firmadas de archivos privados.
    */
    'temporary_url_minutes' => (int) env('FILE_TEMPORARY_URL_MINUTES', 15),

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

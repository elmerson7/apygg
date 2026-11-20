<?php

use App\Exports\TenantMembersExporter;
use App\Exports\UsersExporter;
use App\Imports\UsersImporter;

/*
| Exportar e importar CSV/XLSX y PDF (docs/export-import.md).
*/
return [

    // Formatos de exportación admitidos.
    'formats' => ['csv', 'xlsx'],

    // Filas que se leen por lote al exportar (lazyById: memoria constante).
    // Tiempo máximo (s) de un job de exportar/importar.
    'job_timeout' => (int) env('EXPORT_JOB_TIMEOUT', 600),

    'chunk_size' => (int) env('EXPORT_CHUNK_SIZE', 1000),

    // Días que se conserva el archivo exportado (luego `exports:prune` lo borra) y los datos de importaciones.
    'retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    'import_retention_days' => (int) env('IMPORT_RETENTION_DAYS', 30),

    // Minutos tras los que un trabajo `processing` (worker muerto) se da por fallido (`exports:prune`).
    'stuck_minutes' => (int) env('EXPORT_STUCK_MINUTES', 60),

    // Minutos de validez de la URL temporal de descarga.
    'url_minutes' => (int) env('EXPORT_URL_MINUTES', 30),

    // Exportadores: clave => clase (App\Exports\Exporter). Los proyectos derivados añaden los suyos.
    'exporters' => [
        'users' => UsersExporter::class,
        'tenant-members' => TenantMembersExporter::class,
    ],

    // Importadores: clave => clase (App\Imports\Importer).
    'importers' => [
        'users' => UsersImporter::class,
    ],

    'import' => [
        'max_rows' => (int) env('IMPORT_MAX_ROWS', 50000),
        'max_kb' => (int) env('IMPORT_MAX_KB', 10240),
        'batch_size' => (int) env('IMPORT_BATCH_SIZE', 500),
        'sample_rows' => 10,       // filas válidas de muestra en la vista previa
        'error_sample' => 50,      // primeros errores en la vista previa (el resto, en el informe descargable)
    ],

    'pdf' => [
        'paper' => 'a4',
        'orientation' => 'portrait',
        // Carpeta desde la que dompdf puede leer imágenes/fuentes locales. Nunca URLs remotas.
        'chroot' => resource_path(),
    ],
];

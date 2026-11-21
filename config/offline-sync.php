<?php

/*
|--------------------------------------------------------------------------
| Sincronización offline (docs/offline-sync.md)
|--------------------------------------------------------------------------
|
| Módulo opcional `offline-sync`. Apagado por defecto: sin `OFFLINE_SYNC_ENABLED` no se registra ninguna ruta.
*/
return [
    'enabled' => (bool) env('OFFLINE_SYNC_ENABLED', false),

    // Versiones de UUID aceptadas como ID generado por el cliente (se recomienda v7: ordenable por tiempo).
    'client_id_versions' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('OFFLINE_SYNC_CLIENT_ID_VERSIONS', '4,7'))
    ))),

    // Operaciones máximas por lote en POST /sync/batch.
    'max_operations' => (int) env('OFFLINE_SYNC_MAX_OPERATIONS', 100),

    // Cada operación del lote debe traer su `idempotency_key` (recomendado: un reintento sin clave puede duplicar).
    'require_idempotency_key' => (bool) env('OFFLINE_SYNC_REQUIRE_IDEMPOTENCY_KEY', true),

    // Pull (`updated_since`): tamaño de página por defecto y máximo.
    'page_size' => (int) env('OFFLINE_SYNC_PAGE_SIZE', 100),
    'max_page_size' => (int) env('OFFLINE_SYNC_MAX_PAGE_SIZE', 500),

    // `server_time` = ahora - margen: cubre transacciones que confirman después de leer (el cliente reaplica filas ya vistas).
    'clock_skew_seconds' => (int) env('OFFLINE_SYNC_CLOCK_SKEW_SECONDS', 5),

    /*
    | Recursos sincronizables (nombre público => definición). Solo valores serializables (`config:cache`).
    |
    |   'notes' => [
    |       'model' => App\Models\Note::class,        // hereda de App\Models\Model; con multiempresa, BelongsToTenant
    |       'resource' => App\Http\Resources\NoteResource::class,   // opcional: sin él, $model->toArray()
    |       'permissions' => ['viewAny' => 'notes.read', 'create' => 'notes.create', 'update' => 'notes.update', 'delete' => 'notes.delete'],
    |       'create_rules' => ['title' => 'required|string|max:200'],   // atributos aceptados al crear
    |       'update_rules' => [...],                    // opcional: por defecto, create_rules con `sometimes`
    |       'require_if_match' => false,                // true: update/delete sin `if_match` -> 428
    |   ],
    |
    | Autorización: cada acción exige el permiso declarado Y la policy del modelo si existe; sin ninguno de los dos
    | se deniega (fail-closed).
    */
    'resources' => [],
];

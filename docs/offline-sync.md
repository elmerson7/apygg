# Sincronización offline (módulo opcional `offline-sync`)

Para apps que trabajan sin conexión (móvil/SPA): la app crea y edita en local con **IDs propios**, **baja** los
cambios del servidor con `updated_since` y **sube** su cola de operaciones en lotes idempotentes. Apagado por
defecto: sin `OFFLINE_SYNC_ENABLED=true` no se registra ninguna ruta. Sin migraciones ni tablas propias (reutiliza
`idempotency_keys`, ver `docs/idempotency.md`). Quitable con `make module-remove name=offline-sync`.

Código: `App\Traits\ClientGeneratedId`, `App\Http\Controllers\Concerns\SyncsChanges`,
`App\Contracts\OfflineSyncInterface` / `OptimisticLockInterface`, `App\Http\Controllers\Sync\SyncController`,
`config/offline-sync.php`, `routes/api/sync.php`. Pruebas: `tests/Feature/OfflineSync`,
`tests/Concurrency/SyncBatchConcurrenteTest.php`.

## 1. IDs generados por el cliente

Por modelo (los IDs por defecto del boilerplate siguen siendo enteros). El modelo usa `ClientGeneratedId`
**en lugar de** `HasUuid` (tabla con `uuid('id')->primary()`):

```php
class Note extends Model { use BelongsToTenant, ClientGeneratedId; }
Note::createFromClient($validated, $idDelCliente);   // o null: el servidor genera un UUID v7
```

- Acepta UUID de las versiones de `OFFLINE_SYNC_CLIENT_ID_VERSIONS` (`4,7`; **genera v7**: ordenable por tiempo).
  Inválido: 422 `invalid_client_id`. La regla `App\Rules\ClientGeneratedUuid` sirve para Form Requests propios.
- ID ya existente: 409 `client_id_conflict`, **idéntico** si el registro es de tu tenant, de otro tenant o de otro
  usuario: nunca revela que existe. Para reintentos seguros usa la clave de idempotencia (abajo); un reintento sin
  clave recibe este 409.

## 2. Pull: cambios desde una marca

`GET /sync/{recurso}?updated_since=<ISO8601>&per_page=&cursor=` (genérico, recursos de la config) o, en tus propios
controllers, el concern `SyncsChanges::syncChanges($request, $query, $present)` con `SyncChangesRequest`.

- Incluye los **borrados** (soft delete) como `{"id": "...", "deleted_at": "..."}`; los vivos, su Resource.
- Orden estable `(updated_at, id)` y paginación **por cursor** (`meta.next_cursor`, `meta.has_more`).
- `meta.server_time`: guarda el de la **última** página (`has_more=false`) y envíalo como `updated_since` la próxima
  vez. Es "ahora" menos `OFFLINE_SYNC_CLOCK_SKEW_SECONDS` (5): cubre transacciones que confirman tarde. La app puede
  recibir alguna fila repetida: aplícala con upsert por `id`.
- Los hard deletes no aparecen: usa modelos con soft delete. Sin `updated_since` devuelve todo (primera carga).
- Autorización: permiso `viewAny` del recurso (y policy si existe). Multiempresa: solo ves tu tenant (`BelongsToTenant`).

## 3. Concurrencia optimista (ETag / If-Match)

Cada registro tiene un ETag opaco (hash de la fila leída de la BD; viene en `etag` de cada resultado del lote).
En PATCH/PUT/DELETE el cliente manda `If-Match: "<etag>"` (en lotes, `if_match`):

| Caso | Resultado |
|---|---|
| Coincide | Se aplica (con la fila bloqueada `FOR UPDATE`: dos peticiones con el mismo ETag no pasan las dos) |
| No coincide | 412 `precondition_failed` con `current_etag` |
| Falta y el recurso tiene `require_if_match` | 428 `precondition_required` |
| `*` | Cualquier versión |

En un controller propio: `app(OptimisticLockInterface::class)->guard($model, $this->ifMatchHeader($request),
required: true, fn (Model $fresh) => ...)` y `withEtag($response, $model)` para la cabecera `ETag`.
Conflicto 412: la app vuelve a bajar el registro, resuelve (p. ej. "gana el servidor" o fusión) y reintenta con el
ETag nuevo.

## 4. Push: `POST /sync/batch`

```json
{ "operations": [
  { "idempotency_key": "uuid-de-esta-operacion", "resource": "notes", "op": "create",
    "id": "0192...v7", "data": { "title": "Hola" } },
  { "idempotency_key": "...", "resource": "notes", "op": "update", "id": "...", "data": {"title": "x"}, "if_match": "\"abc\"" },
  { "idempotency_key": "...", "resource": "notes", "op": "delete", "id": "..." }
] }
```

Respuesta **200** con `data.results[]` (mismo orden) + `total/succeeded/failed`. Cada resultado:
`idempotency_key, resource, op, id, status, ok, data, etag, replayed, error{code,message,errors}`.

- **Idempotencia por operación**: reserva atómica por (tenant, usuario, clave) en `idempotency_keys` (clave guardada
  como `sync:<clave>`). Repetir la clave devuelve el resultado guardado con `replayed: true` (el `data` es la
  foto original, no el estado actual); misma clave con otra operación: `idempotency_key_mismatch` (422); en curso:
  `idempotency_key_in_progress` (409). Los 4xx se guardan (usa otra clave tras corregir); un 5xx libera la clave.
  Dos lotes simultáneos con la misma clave ejecutan una sola vez.
- **Aislamiento**: cada operación va en su propia transacción; las demás siguen si una falla.
- **Permisos**: por operación y recurso: el permiso declarado (`permissions.create|update|delete`) **y** la policy
  del modelo si existe; sin ninguno de los dos, 403 (fail-closed). Con multiempresa activa, un modelo sin
  `BelongsToTenant` no se sincroniza. Registro de otro tenant: 404, idéntico a un id inexistente.
- `delete` de algo ya borrado: 200 (idempotente). Límite: `OFFLINE_SYNC_MAX_OPERATIONS` (100) y clave obligatoria
  (`OFFLINE_SYNC_REQUIRE_IDEMPOTENCY_KEY`). Solo JWT de usuario (no API keys).

## 5. Configurar un recurso

En `config/offline-sync.php` -> `resources` (solo valores serializables) ver el ejemplo comentado: `model`,
`resource` (opcional), `permissions` (`viewAny|create|update|delete`; cada permiso va también en
`PermissionSeeder::catalog()` del proyecto), `create_rules`, `update_rules` (por defecto `create_rules` con
`sometimes`) y `require_if_match`. El boilerplate no trae ningún recurso: el de pruebas es
`tests/Support/SyncDemoItem.php`.

## 6. Cómo sincroniza la app

1. Primera carga: `GET /sync/notes` hasta `has_more=false`; guarda `server_time` de la última página.
2. Trabajo offline: cada cambio local entra en una cola con su `idempotency_key` (UUID) y el `etag` que conoce.
3. Con red: **push** (`POST /sync/batch`) y luego **pull** (`updated_since=<server_time guardado>`). Para cada
   resultado: `ok` -> quita de la cola y guarda `etag`; 412 -> conflicto (ver abajo); 5xx/red -> reintenta con la
   MISMA clave; otros 4xx -> error del usuario, descarta o corrige con otra clave.
4. Conflictos (412): descarga el registro, decide (servidor gana / cliente gana / fusión por campos) y reenvía
   con el `current_etag`.

## Límites

Sin resolución de conflictos automática (la política es de la app); sin sincronización de relaciones anidadas en un
solo registro; los hard deletes no se propagan; el `data` de un resultado repetido es el original.

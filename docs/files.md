# Archivos

Subida, descarga y borrado en `/files` (`FileController`, `FileService`, `FilePolicy`).

## Subida (`POST /files`)

- El tipo se decide por el **contenido** (finfo), no por el nombre ni el `Content-Type` del cliente.
- Lista negra en `config/files.php` (`blocked_mimes`, `blocked_extensions`): SVG, HTML, XML, JS y PHP se rechazan siempre (422), porque se ejecutarían en el origen de la API.
- El nombre en disco se **genera** (ULID + extensión deducida del contenido). El nombre original solo se guarda, limpio de rutas, en `name`.
- Tamaño máximo por tipo: `config/files.php` -> `max_sizes` (`FILE_MAX_SIZE_*`).

## Clave en disco: prefijo de entorno y tenant

`FileService::tenantStoragePath()` construye la clave de todo archivo NUEVO: `[<FILES_PREFIX>/][tenants/<id>/]<ruta>/<ulid>.<ext>`.

- `FILES_PREFIX` (`config/files.php` -> `prefix`, por defecto `APP_ENV`) separa entornos que comparten bucket. Un solo segmento
  (se sanea a `[A-Za-z0-9._-]`); vacío = sin prefijo.
- Con multiempresa activo la clave lleva además `tenants/<id>/`: un tenant nunca comparte carpeta con otro y se puede medir o purgar por empresa.
- Los archivos ya guardados conservan su `path` en BD: cambiar el prefijo **no** los mueve ni los rompe (ver `docs/upgrading.md`).

## Público y privado

El disco depende de `is_public`, no de la categoría:

| `is_public` | Disco (`config/files.php` -> `disks`) | Acceso |
|---|---|---|
| `true` | `FILE_DISK_PUBLIC` (`public` / `s3`) | URL estable sin autenticación |
| `false` (por defecto) | `FILE_DISK_PRIVATE` (`local` / `s3_private`) | nunca hay URL pública |

Un archivo privado se obtiene por:

- `GET /files/{id}/download`: JWT y ser dueño, o tener el permiso `files.manage`.
- `GET /files/{id}/temporary-url`: devuelve una URL firmada que caduca (`FILE_TEMPORARY_URL_MINUTES`, 15 por defecto). En S3/MinIO es una URL prefirmada; en disco local, la ruta firmada `files.signed`.

Cambiar `is_public` con `PUT /files/{id}` mueve el archivo de disco.

## Subida directa presignada (`POST /files/uploads`)

Para archivos grandes: el cliente sube directo al bucket y la API no toca los bytes. Tres pasos:

1. `POST /files/uploads` con `name`, `size`, `mime_type` (y opcional `checksum_sha256` en hex, `category`, `description`, `is_public`).
   Valida lo declarado con las mismas reglas que `POST /files` (lista negra, extensiones, tamaño por tipo y `max_file_mb` del plan),
   **reserva cuota** y responde 201 con `id` (uuid), `expires_at` y `upload: {method: "PUT", url, headers, expires_at}`.
   Acepta `Idempotency-Key` (opcional). Si el disco no firma subidas: 422 `direct_upload_unsupported`.
2. El cliente hace `PUT <url>` con las `headers` dadas y el contenido.
3. `POST /files/uploads/{id}/complete` comprueba el objeto REAL: existe (si no, 422 `upload_object_missing` y se puede reintentar),
   **tamaño** igual al declarado (`upload_size_mismatch`), **tipo por contenido** (finfo sobre los primeros bytes; `upload_mime_mismatch`,
   p. ej. HTML declarado como PNG) y **checksum** si se declaró (`upload_checksum_mismatch`). Cualquier discrepancia borra el objeto y
   descarta la intención (`upload_intent_aborted` después). Si todo cuadra crea el `File` y lo devuelve (200). Completar de nuevo
   es idempotente y devuelve el mismo `File`. Solo quien creó la intención (o `files.manage`) la completa.

La intención (`file_upload_intents`, `FileUploadIntent`, por tenant) vive `FILE_DIRECT_UPLOAD_MINUTES` (30). Caducada, ya no se completa y
deja de reservar cuota. `files:purge-intents` (cada hora) borra el objeto huérfano de las caducadas y las filas cerradas con más de
`FILE_DIRECT_UPLOAD_RETENTION_DAYS` (7) días. En S3/MinIO la URL es una PUT prefirmada del proveedor; un disco local con `serve` firma
una ruta propia de Laravel.

## Cuotas por plan

Con `PLAN_LIMITS_ENABLED=true` y multiempresa (`docs/settings-and-flags.md` §3), cada tenant tiene dos límites en `config/plans.php`
(sobrescribibles por tenant): `max_storage_mb` (almacenamiento total) y `max_file_mb` (tamaño máximo por archivo). `null` = ilimitado.

| Caso | Respuesta |
|---|---|
| Archivo mayor que `max_file_mb` (subida normal o intención) | 413 `file_too_large_for_plan` (`limit_bytes`) |
| Subir lo dejaría por encima de `max_storage_mb` | 403 `storage_quota_exceeded` (`limit_bytes`, `used_bytes`, `adding_bytes`) |

**Uso** = suma de `files.size` (archivos vivos; un archivo borrado libera cuota) + `file_upload_intents.size` pendientes y vigentes (reserva).
**Concurrencia**: `FileService::withStorageQuota()` usa `PlanLimits::guarded`: transacción con la fila del tenant bloqueada (`FOR UPDATE`),
suma el uso y solo entonces crea. Dos subidas simultáneas se serializan y no pueden pasarse (prueba con procesos reales en
`tests/Concurrency/CuotaDeArchivosConcurrenteTest.php`). En la subida normal el bloqueo incluye la escritura a disco (es la que cuenta
el tamaño real); la presignada solo bloquea el instante de reservar, y al completar los mismos bytes pasan de reserva a archivo sin
cambiar el total. `GET /files/usage` devuelve `used_bytes`, `limit_bytes`, `remaining_bytes` y `max_file_bytes` del tenant.

## S3 / emulador local

Para desarrollo, `make up-dev` levanta un S3 local con los dos buckets (ver `docs/local-services.md`).

Usa un bucket **privado** distinto para los privados: `FILE_DISK_PUBLIC=s3`, `FILE_DISK_PRIVATE=s3_private`, `AWS_PRIVATE_BUCKET=<bucket privado>` (disco `s3_private` en `config/filesystems.php`). Ver `docs/local-services.md`.

## Servir `/storage`

Los archivos públicos los sirve el servidor web como estáticos. Como SVG/HTML/JS no se pueden subir, no hay contenido activo; el `Caddyfile` ya añade `X-Content-Type-Options: nosniff`.

## Permiso

`files.manage`: ver, descargar, editar y borrar archivos de otros usuarios (el rol `admin` lo tiene siempre).

## Despliegue: `storage:check`

`php artisan storage:check [--disk=...]` prueba cada disco (escritura, lectura, URL y borrado, bajo el prefijo de entorno) y avisa si el disco
puede firmar subidas directas. Ejecútalo tras desplegar y tras cambiar credenciales o buckets.

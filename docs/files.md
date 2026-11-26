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

## Subida por partes (S3 multipart, archivos grandes)

Para archivos de cientos de MB o GB: el cliente parte el archivo, sube cada parte con su URL prefirmada (en paralelo y con reintento por
parte) y completa al final. Es la misma intención de subida directa (`file_upload_intents`), con una fila 1 a 1 en
`file_multipart_uploads` (`upload_id` del proveedor, `part_size`, `parts_count`). Solo discos S3; con otro disco o
`FILE_MULTIPART_ENABLED=false`: 422 `multipart_unsupported`.

| Paso | Ruta | Detalle |
|---|---|---|
| 1. Crear | `POST /files/uploads/multipart` | Mismo cuerpo y validaciones que `POST /files/uploads`. **Reserva cuota** bajo el bloqueo del tenant (solo el instante de reservar; la llamada al proveedor va fuera del bloqueo y, si falla, la intención se cierra y la cuota vuelve). Responde 201 con `id`, `part_size` y `parts_count`. Acepta `Idempotency-Key`. |
| 2. URLs | `POST /files/uploads/{id}/parts` con `part_numbers: [1,2,...]` | Hasta `FILE_MULTIPART_MAX_URLS` (100) por petición; devuelve `parts: [{part_number, url, headers}]` y `expires_at` (`FILE_MULTIPART_URL_MINUTES`, sin pasar de la caducidad de la intención). El cliente hace `PUT <url>` con el contenido de esa parte. Todas miden `part_size` salvo la última. |
| (reanudar) | `GET /files/uploads/{id}/parts` | Partes ya subidas (`part_number`, `size`, `etag`), consultadas al proveedor. |
| 3. Completar | `POST /files/uploads/{id}/complete` | El servidor lista las partes (no se fía de lo que diga el cliente): faltan -> 422 `upload_parts_missing` (reintentable); tamaño de parte distinto -> `upload_part_mismatch` (descarta); las une y verifica el objeto REAL como la subida directa (tamaño, tipo por contenido, checksum). |
| Abortar | `DELETE /files/uploads/{id}` | Aborta en el proveedor, borra lo subido y **libera la reserva**. Idempotente; una subida ya completada responde 409 `upload_intent_completed`. Sirve también para intenciones simples. |

- **Tamaño de parte**: `FILE_MULTIPART_PART_MB` (16; mínimo 5, límite de S3). Si el archivo necesitaría más de `FILE_MULTIPART_MAX_PARTS` (10000, tope de S3) el servidor agranda la parte (múltiplo de 1 MiB; máx. 5 GiB). El tope de tamaño es `FILE_MULTIPART_MAX_MB` (5120) en lugar del tope por tipo de `max_sizes`, salvo los tipos de `multipart.max_sizes_mb` (por defecto las imágenes: `FILE_MULTIPART_MAX_MB_IMAGE`, 5); el `max_file_mb` del plan y la cuota siguen mandando.
- **Vigencia**: `FILE_MULTIPART_MINUTES` (1440). Caducada, deja de reservar cuota y de aceptar partes.
- **Idempotencia y concurrencia de completar**: la fila de la intención se bloquea (`FOR UPDATE`); dos `complete` simultáneos se serializan, el segundo ve la intención `completed` y devuelve el mismo `File` (una sola unión, un solo archivo). Si la unión se hizo en el proveedor pero el commit en BD se perdió, el reintento ve que el proveedor ya no conoce la subida y verifica el objeto ya unido. Un fallo del proveedor (listar/unir) responde 502 `upload_storage_error` sin cambiar el estado: se reintenta. Ojo: el bloqueo de ESA intención dura lo que dure la unión y la verificación (con `checksum_sha256` se relee el objeto entero); no bloquea al tenant.
- **Aislamiento y permisos**: igual que la subida directa (la intención es por tenant, otro tenant recibe 404; solo quien la creó o `files.manage` opera sobre ella, 403). No hay permisos nuevos.
- **Limpieza**: `files:purge-intents` (cada hora) aborta en el proveedor las subidas por partes caducadas y borra la fila; si el proveedor no deja abortar, **conserva la fila** y reintenta en el siguiente barrido (así no quedan partes huérfanas cobrando). Recomendado además, en el bucket: regla de ciclo de vida `AbortIncompleteMultipartUpload` a 7 días como red de seguridad.
- **CORS del bucket**: para subir desde el navegador, el bucket debe permitir `PUT` y exponer la cabecera `ETag`.
- **Pruebas**: `tests/Feature/Files/SubidaPorPartesTest.php` (fake `Tests\Support\FakeMultipartStorage`), `tests/Concurrency/SubidaPorPartesConcurrenteTest.php` (procesos reales) y `tests/Integration/SubidaPorPartesS3Test.php` contra RustFS (`make test-integration`).

## Cuotas por plan

Con `PLAN_LIMITS_ENABLED=true` y multiempresa (`docs/settings-and-flags.md` §3), cada tenant tiene dos límites en `config/plans.php`
(sobrescribibles por tenant): `max_storage_mb` (almacenamiento total) y `max_file_mb` (tamaño máximo por archivo). `null` = ilimitado.

| Caso | Respuesta |
|---|---|
| Archivo mayor que `max_file_mb` (subida normal o intención) | 413 `file_too_large_for_plan` (`limit_bytes`) |
| Subir lo dejaría por encima de `max_storage_mb` | 403 `storage_quota_exceeded` (`limit_bytes`, `used_bytes`, `adding_bytes`) |

**Uso** = suma de `files.size` (archivos vivos; un archivo borrado libera cuota) + `file_upload_intents.size` pendientes y vigentes (reserva; también las multipart, hasta completar, abortar o caducar).
**Concurrencia**: `FileService::withStorageQuota()` usa `PlanLimits::guarded`: transacción con la fila del tenant bloqueada (`FOR UPDATE`),
suma el uso y solo entonces crea. Dos subidas simultáneas se serializan y no pueden pasarse (prueba con procesos reales en
`tests/Concurrency/CuotaDeArchivosConcurrenteTest.php`). En la subida normal el bloqueo incluye la escritura a disco (es la que cuenta
el tamaño real); la presignada solo bloquea el instante de reservar, y al completar los mismos bytes pasan de reserva a archivo sin
cambiar el total. `GET /files/usage` devuelve `used_bytes`, `limit_bytes`, `remaining_bytes` y `max_file_bytes` del tenant.

## Protección sin antivirus (siempre activa)

Lo que cubre la mayor parte del riesgo sin gastar memoria:

- Tipo por **contenido** (finfo), lista blanca (`allowed_mimes`/`allowed_extensions`) y lista negra de lo ejecutable (SVG, HTML, JS, PHP...).
- Nombre en disco **generado**; el original nunca se usa como ruta.
- Descarga por la API siempre como **adjunto** con `X-Content-Type-Options: nosniff` y `Content-Security-Policy: default-src 'none'; sandbox`.
  Las URL temporales de S3 fuerzan `Content-Disposition: attachment`.
- Recomendado en producción: servir los archivos públicos desde **otro dominio** (bucket/CDN), no desde el de la API.
- Opcional, **reprocesar imágenes** (`FILE_IMAGE_REENCODE=true`, `config/files.php` -> `image_reencode`): en `POST /files`, JPEG, PNG y
  WebP se decodifican y se vuelven a codificar con GD. Se pierde lo escondido tras la imagen (archivo políglota) y los metadatos EXIF
  (ubicación GPS, cámara). Cambia los bytes y el tamaño; gasta CPU; las de más de `FILE_IMAGE_REENCODE_MAX_MP` (40) megapíxeles se
  rechazan (422) para no agotar la memoria. Los GIF no se tocan (perderían la animación) y la subida directa a S3 no pasa por aquí.

## Escaneo antivirus (opcional, apagado por defecto)

`FILE_SCAN_ENABLED=false` por defecto: todo funciona como antes y ningún contenedor de antivirus arranca. Encendido:

1. Todo archivo nuevo (subida normal, directa o por partes) nace con `scan_status = pending`: **cuarentena**. No tiene `url` ni `path` en
   la respuesta y `GET /files/{id}/download`, `/temporary-url` y la URL firmada responden **409 `file_scan_pending`** (la autorización va
   antes: quien no puede verlo recibe 403).
2. `ScanFileJob` (en cola, tras el commit, en el tenant del archivo) pasa el contenido por los motores de `FILE_SCAN_DRIVERS`, en orden:
   - limpio en todos -> `clean`: se sirve normal (el público recupera su URL);
   - uno detecta malware -> `infected`: **se borra el contenido** del almacenamiento, la fila queda como registro (`scan_result` = motor y
     firma), deja de contar en la cuota, se registra en el canal `security` y se lanza el evento `App\Events\FileInfected` (escúchalo
     para avisar). Descarga: **410 `file_infected`**;
   - fallo pasajero (clamd caído, 429 de VirusTotal) -> el job reintenta (`FILE_SCAN_TRIES`, 5, con espera creciente). Agotados ->
     `failed`, sigue en cuarentena (**409 `file_scan_failed`**). Un fallo que no se arregla reintentando (clave inválida) va directo a `failed`.
3. Lo que pase de `FILE_SCAN_MAX_MB` (100) no se escanea: `FILE_SCAN_OVERSIZE=block` (por defecto) lo deja `failed`; `allow` lo deja
   `clean` con la nota "sin escanear". Debe ser <= `CLAMAV_MAX_MB` (StreamMaxLength de clamd).
4. `php artisan files:scan [--status=pending,failed] [--limit=1000]` vuelve a encolar (tras una caída de clamd). Con `--status=skipped`
   revisa lo subido **antes** de encender el escaneo (esos archivos pasan a `pending` y quedan en cuarentena hasta escanearse).

Estados (`App\Enums\FileScanStatus`, campo `scan_status` de la respuesta): `skipped` (sin escaneo; los archivos anteriores a esta
función), `pending`, `clean`, `infected`, `failed`. Solo `skipped` y `clean` se sirven.

**Limitación:** en un disco público (bucket público o `/storage`) el objeto existe desde la subida. Mientras está en cuarentena la API no
da su ruta ni su URL (la clave lleva un ULID: no se adivina), pero no está físicamente aislado. Si necesitas aislamiento estricto, deja
`security.allow_public_sharing` apagado (los archivos van al disco privado) o usa un bucket privado.

### Motores

| `FILE_SCAN_DRIVERS` | Qué hace | Coste |
|---|---|---|
| `clamav` | Envía el contenido a clamd (`CLAMAV_HOST`:`CLAMAV_PORT`) con INSTREAM por TCP; no comparte disco. Detecta malware conocido y patrones. | **~1 GB de RAM fijos** (medido: 960 MB con las firmas cargadas). Gratis. |
| `virustotal` | Consulta solo el **SHA-256** en VirusTotal (`VIRUSTOTAL_API_KEY`); el archivo **no sale** del servidor. Infectado si `VIRUSTOTAL_MIN_DETECTIONS` (2) motores lo marcan. Un hash desconocido cuenta como limpio: solo detecta malware ya conocido. | Sin RAM. La clave gratuita es **solo para uso no comercial** (4 peticiones/min, 500/día); para un producto comercial hace falta la de pago. |
| `clamav,virustotal` | Los dos en orden; el primero que detecta decide. | |

Un proyecto puede añadir su motor (p. ej. un servicio de pago): una clase que implemente `App\Contracts\MalwareScannerInterface` y su
nombre en `config/files.php` -> `scanning.engines`.

### Contenedor ClamAV (perfil `clamav`)

Nunca arranca si no se pide: está en el perfil de Compose `clamav` (`clamav/clamav:1.5.5`, versión fija).

- Local: `make up CLAMAV=true` (con `FILE_SCAN_ENABLED=true` y `FILE_SCAN_DRIVERS=clamav` en `.env`).
- Producción (`deploy/compose.prod.yml`): `COMPOSE_PROFILES_EXTRA="clamav" make deploy`.
- La primera vez descarga las firmas (~1 min); se guardan en el volumen `<PROJECT>-clamavdb`. `freshclam` las actualiza
  `CLAMAV_FRESHCLAM_CHECKS` (4) veces al día.
- `ConcurrentDatabaseReload no`: al recargar firmas no se duplica la memoria (si no, el pico llega a ~2 GB); a cambio clamd no
  escanea unos segundos y el job reintenta. Límite de memoria del contenedor: `CLAMAV_MEMORY_LIMIT` (2g).
- Probar contra clamd real: `INTEGRATION_CLAMAV_HOST=clamav make test-integration` (`tests/Integration/ClamAvIntegracionTest.php`, con EICAR).

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

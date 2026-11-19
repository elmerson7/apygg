# Archivos

Subida, descarga y borrado en `/files` (`FileController`, `FileService`, `FilePolicy`).

## Subida (`POST /files`)

- El tipo se decide por el **contenido** (finfo), no por el nombre ni el `Content-Type` del cliente.
- Lista negra en `config/files.php` (`blocked_mimes`, `blocked_extensions`): SVG, HTML, XML, JS y PHP se rechazan siempre (422), porque se ejecutarían en el origen de la API.
- El nombre en disco se **genera** (ULID + extensión deducida del contenido). El nombre original solo se guarda, limpio de rutas, en `name`.
- Tamaño máximo por tipo: `config/files.php` -> `max_sizes` (`FILE_MAX_SIZE_*`).

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

## S3 / emulador local

Para desarrollo, `make up-dev` levanta un S3 local con los dos buckets (ver `docs/local-services.md`).

Usa un bucket **privado** distinto para los privados: `FILE_DISK_PUBLIC=s3`, `FILE_DISK_PRIVATE=s3_private`, `AWS_PRIVATE_BUCKET=<bucket privado>` (disco `s3_private` en `config/filesystems.php`). Ver `docs/local-services.md`.

## Servir `/storage`

Los archivos públicos los sirve el servidor web como estáticos. Como SVG/HTML/JS no se pueden subir, no hay contenido activo; el `Caddyfile` ya añade `X-Content-Type-Options: nosniff`.

## Permiso

`files.manage`: ver, descargar, editar y borrar archivos de otros usuarios (el rol `admin` lo tiene siempre).

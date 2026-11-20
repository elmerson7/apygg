# Exportar e importar CSV/XLSX y PDF

Módulo genérico para sacar datos a CSV/XLSX, importarlos con vista previa y generar PDF. Todo se
configura en `config/export.php`; los proyectos derivados solo añaden **exportadores** e **importadores**.

## Exportar

`App\Exports\Exporter` declara una exportación: `key()`, `permission()`, `columns()` (clave, cabecera
traducida en `lang/*/export.php`, formato `text|int|decimal|bool|date|datetime`), `filterRules()` y
`query($filters, $tenantId)`. Se registra en `config/export.php` → `exporters`.

Flujo: `POST` crea un `DataExport` (`pending`) y encola `ExportJob` (202). El job lee con `lazyById` por
lotes (`export.chunk_size`) y escribe fila a fila con openspout: la memoria no crece con el tamaño. El archivo
queda en el disco privado vía `FileService` (con multiempresa, bajo `[<prefijo de entorno>/]tenants/<id>/exports/`), la fila pasa a
`completed` y se avisa al usuario con `NotificationServiceInterface::notify()` (tipo `export_ready`, canales `database` + `mail`, declarado en `config/notifications.php`; el usuario puede desactivarlo en sus preferencias). `GET /…/exports/{id}` devuelve `download_url`
(URL temporal, `export.url_minutes`); `GET /…/exports/{id}/download` descarga autenticado. Los archivos caducan
(`export.retention_days`) y `exports:prune` (cada hora) los borra.

| Ruta | Permiso |
|---|---|
| `POST /admin/exports/users`, `GET /admin/exports[/{id}[/download]]` | `users.export` (plataforma) |
| `POST /tenant/exports/members`, `GET /tenant/exports[/{id}[/download]]` | `tenant-members.export` (tenant activo) |

Reglas: cada usuario ve **solo sus** exportaciones del tenant activo (`DataExport::visibleTo`); las de
plataforma (`tenant_id` null) y las de un tenant nunca se mezclan; el exportador de tenant **debe** filtrar
por `$tenantId`. Una celda de texto que empieza por `= + - @` se escribe con `'` delante (inyección de
fórmulas). Un fallo deja la exportación `failed` con su `error` y no se reintenta. Usa el header
`Idempotency-Key` para no duplicar peticiones.

## Importar

`App\Imports\Importer`: `requiredHeaders()`, `optionalHeaders()`, `rules()` (reglas de Laravel por columna) y
`process($rows, $tenantId)`, que **debe ser idempotente por clave natural** (lo que ya existe se cuenta como
`skipped`). Se registra en `importers`.

1. `POST /admin/imports/users` (multipart `file`, csv/txt/xlsx, máx. `import.max_kb`): guarda el archivo, crea
   `DataImport` (`validating`) y encola `PreviewImportJob`.
2. La vista previa valida **todas** las filas en streaming sin guardar nada: `valid_rows`, `invalid_rows`,
   `preview.sample`, `preview.errors` (primeros `error_sample`) y un informe CSV completo
   (`GET /admin/imports/{id}/errors`). Estado `previewed`; si faltan columnas o hay más de `max_rows`, `failed`.
3. `POST /admin/imports/{id}/confirm`: cambio atómico `previewed → queued` (solo una vez; la segunda da 409
   `import_not_confirmable`; sin filas válidas, 422 `import_nothing_to_import`) y encola `ProcessImportJob`.
4. El job revalida y guarda por lotes (`import.batch_size`). Repetir el archivo o el job no duplica nada.
   Estado final `completed` con `created_rows`/`skipped_rows` y aviso `import_finished`; el informe incluye también lo que falló al guardar.

Demo `users` (`users.import`, plataforma): columnas `email`, `name`, opcional `username`; omite emails que
ya existen (sin distinguir mayúsculas); **no importa roles** (reciben el rol por defecto) y no define contraseña
utilizable (deben usar «olvidé mi contraseña»). Cada alta dispara `UserCreated` (correo de bienvenida).
Con multiempresa las importaciones llevan el `tenant_id` activo y los jobs corren en ese tenant; un importador de
datos de empresa debe declarar `requiresTenant()` y escribir siempre dentro del tenant. `exports:prune` borra las
importaciones de más de `import_retention_days`.

## Cuotas de almacenamiento y trabajos atascados

- Los archivos exportados y los informes de errores **no cuentan** para `max_storage_mb` del plan: no son filas de `files`
  (la cuota suma `files.size`), son temporales (`retention_days`, `exports:prune`) y los genera el sistema, no el usuario.
  Si un SaaS quiere contarlos, debe guardarlos como `File` dentro de `FileService::withStorageQuota()`.
- Si un worker muere, una exportación queda en `processing` y una importación en `validating|queued|processing`. `exports:prune`
  (cada hora) las pasa a `failed` cuando llevan más de `export.stuck_minutes` (60) sin actualizarse; una importación `failed` se
  vuelve a subir sin duplicar datos. Una `previewed` esperando confirmación no se toca.

## PDF

Un único servicio: `App\Contracts\PdfRendererInterface` (`render($html, $opts)`, `renderView($vista, $datos, $opts)`),
implementado con **dompdf** (PHP puro, sin binarios). Papel `a3|a4|a5|letter|legal`, orientación
`portrait|landscape`. Sin PHP embebido ni recursos remotos; archivos locales solo bajo `export.pdf.chroot`.
Se quitaron `laravel-snappy`, `fpdf`, `fpdi` y `wkhtmltopdf` (`docs/upgrading.md`). El PDF no es un formato
de exportación masiva (dompdf carga el documento en memoria): úsalo para documentos (facturas, informes).

## Pruebas

`tests/Feature/ExportImport/` (CSV/XLSX, streaming, filtros, fórmulas, preview, confirmación, idempotencia,
permisos), `tests/Feature/Tenancy/Aislamiento/ExportarAislamientoTest.php`, `tests/Concurrency/ImportacionConcurrenteTest.php`
y `tests/Unit/PdfRendererTest.php`.

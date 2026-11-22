# Logs sin datos personales ni secretos

**Regla**: ni datos personales ni secretos llegan a un log (archivo, BD) ni a Sentry. Hay un único
punto de redacción, `App\Helpers\Redactor`, configurado en `config/logging.php` → `redact`.

## Dónde se aplica

| Destino | Mecanismo |
|---|---|
| Archivos (todos los canales de `config/logging.php`) | `tap` → `App\Logging\RedactLogs` → `RedactingProcessor` (mensaje, contexto y extra) |
| BD (`logs_api`, `logs_activity`, `logs_security`) | trait `RedactsLogData` en el modelo (al guardar, cualquier camino) + los loggers (`ApiLogger`...) |
| Sentry | `before_send` / `before_breadcrumb` → `App\Logging\SentryScrubber` (request, extra, contexts, excepciones, breadcrumbs; el usuario queda solo con su id) |
| Back-office | `Pretty::json()` vuelve a redactar al mostrar |

Un canal nuevo en `config/logging.php` debe llevar `'tap' => [RedactLogs::class]`.

## Seguir una petición por los logs

Las tres tablas guardan `trace_id` (texto de hasta 64): es el `request_id` de la respuesta y la cabecera
`X-Request-ID` (el del cliente si es válido, si no un UUID; `TraceIdMiddleware`). En `logs_activity` queda nulo
cuando el cambio viene de consola o de una cola. `GET /admin/logs/{activity|api|security}?filter[trace_id]=<id>`
devuelve lo que dejó esa petición en cada tabla.

## Producción: JSON por stderr

`.env.production.example` deja `LOG_STACK=stderr,sentry_logs` y `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`:
una línea JSON por evento (`message`, `level_name`, `context.trace_id`...), ya redactada. Los canales con fecha
(`activity`, `security`, `auth`) siguen escribiendo en `storage/logs`, que en la imagen de producción no persiste:
lo que importa de ellos ya está en sus tablas.

## Qué se redacta

- **Por nombre de clave** (`keys` exacto, `key_fragments` contiene): `password`, `token`, `secret`,
  `authorization`, `cookie`, `api_key`, `card_number`, `cvv`, `iban`... → `[REDACTED]`.
- **Por patrón en cualquier texto** (`patterns`): `email` (`j***@d***.com`), `card` (validada con Luhn,
  `****1234`), `phone` (internacional con `+`, `****3456`), `jwt`, `bearer`.
- `mode`: `mask` (pista parcial) o `remove` (se elimina la clave / se sustituye por `[REDACTED]`).
  Variable `LOG_REDACT_MODE`.
- Límites: `max_depth` (profundidad; más abajo `[MAX_DEPTH]`) y `max_string` (cadena analizada, el resto se corta).
- Los objetos (excepciones) del contexto no se recorren; el mensaje de la excepción sí se redacta en Sentry.

## Cómo añadir claves o patrones

Añade el nombre a `redact.keys` (exacto) o un fragmento a `redact.key_fragments`. Un patrón nuevo se
implementa en `Redactor::redactString()` y se activa en `redact.patterns`. Cubre el caso en
`tests/Unit/Logging/RedactorPatronesTest.php`.

## Límites conocidos

Los teléfonos sin prefijo `+` no se detectan por patrón (evita falsos positivos con ids): si un campo
es un teléfono, añade su nombre a `keys`. Un número de 13-19 dígitos que pase Luhn por casualidad se
enmascara.

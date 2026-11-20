# Respuestas API

Hay **tres formas** de respuesta, según quién las produce. Los textos de mensaje
de ejemplo son los del código real; los de errores del framework salen en
inglés porque así los fija `bootstrap/app.php`.

## 1. Éxito y errores de controller (`sendSuccess` / `sendError` / `sendPaginated`)

Vienen de `RespondsWithApi` (base `Controller`). **No llevan `meta`** salvo en
listados paginados, donde `meta` es la paginación.

```json
{ "success": true, "message": "Operación exitosa", "data": { "id": 1 } }
```

```json
{ "success": false, "message": "Credenciales inválidas. Intentos restantes: 4",
  "code": "unauthenticated", "request_id": "3f2b8c1e-6a4d-4e0b-9d57-1c2a7b9e5f10" }
```

`sendError` añade `"errors": { ... }` solo si se le pasan, y acepta un `code`
propio (ver "Contrato común de errores").

**Paginado** (`sendPaginated`, sin `message`):

```json
{
  "success": true,
  "data": [ ... ],
  "meta": { "current_page": 1, "per_page": 15, "total": 9, "last_page": 1, "from": 1, "to": 9 },
  "links": { "first": "...", "last": "...", "prev": null, "next": null }
}
```

**Regla fija:** el contenido del recurso SIEMPRE va dentro de `data`. Nunca se
devuelven propiedades sueltas al mismo nivel que `success`/`data`.

| Código | Uso |
|--------|-----|
| 200 | GET, PUT, PATCH, DELETE exitoso |
| 201 | POST (creación) |

## 1b. Contrato común de errores: `code` y `request_id`

**Toda** respuesta de error (4xx/5xx), venga de donde venga (`sendError`, handler
RFC 7807, `ApiResponse`, middleware), lleva además de los campos que ya tenía:

- `code`: código estable y legible por máquina (`snake_case`). **Los clientes
  ramifican por `code`**, no por `message` (que cambia con el idioma) ni solo por
  el status. Un `code` publicado no se renombra.
- `request_id`: el identificador de la petición, el mismo que la cabecera
  `X-Request-ID` (y `X-Trace-ID`, alias) y que el campo `trace_id` de los logs.
  Pídelo al usuario al reportar un fallo de soporte: localiza la traza exacta.

`X-Request-ID` va en **todas** las respuestas, también las correctas y los
404/405 sin ruta. Si el cliente envía `X-Request-ID` (o `X-Trace-ID`) se respeta
solo si son 1 a 64 caracteres de `A-Za-z0-9._:-` (un UUID vale); si no, se
genera un UUID. Lo añade `TraceIdMiddleware` (global, sin estado estático).

### Códigos

El status HTTP no cambia por el `code`; la columna indica el status habitual.

| `code` | Status | Cuándo |
|--------|--------|--------|
| `bad_request` | 400 | Solicitud malformada |
| `validation_failed` | 422 | Datos inválidos (`errors` por campo) |
| `unauthenticated` | 401 | Sin sesión o credenciales ausentes/incorrectas |
| `token_expired` | 401 | JWT expirado |
| `token_invalid` | 401 | JWT inválido, manipulado o revocado |
| `forbidden` | 403 | Sin permiso, rol o policy |
| `not_found` | 404 | Ruta o recurso inexistente |
| `method_not_allowed` | 405 | Método HTTP no admitido en la ruta |
| `conflict` | 409 | Choca con el estado actual |
| `duplicate` | 422 | Violación de unicidad en BD (carrera con la validación `unique`) |
| `business_rule` | 422 | `BusinessLogicException` (el detalle va en `business_code`) |
| `payload_too_large` | 413 | Cuerpo demasiado grande |
| `rate_limited` | 429 | Límite de peticiones (ver `Retry-After`) |
| `account_locked` | 401 | Login bloqueado por demasiados intentos (`lockout_minutes`) |
| `account_blocked` | 403 | Identidad en la lista de bloqueo |
| `two_factor_invalid` | 401 | Código 2FA (TOTP o de recuperación) o desafío de login inválido, caducado o agotado |
| `email_not_verified` | 403 | La ruta exige correo verificado (middleware `verified`) |
| `app_update_required` | 426 | La versión de la app (`X-App-Version`) es menor que la mínima configurada (`minimum_version`) |
| `feature_disabled` | 403 | Feature flag apagado |
| `ip_not_allowed` | 403 | IP fuera de la lista blanca |
| `api_key_required` | 401 | La ruta exige API Key |
| `api_key_invalid` | 401 | API Key no encontrada o revocada |
| `insufficient_scope` | 403 | La API Key no tiene el scope |
| `tenant_invalid` | 403 | Tenant inexistente o sin acceso (misma respuesta en ambos casos) |
| `tenant_inactive` | 403 | Tenant suspendido o inactivo |
| `tenant_required` | 400 | La ruta exige indicar el tenant (cabecera `X-Tenant`, ruta o subdominio) |
| `cross_tenant` | 403 | El recurso pertenece a otro tenant |
| `idempotency_key_required` | 400 | La ruta exige la cabecera `Idempotency-Key` (o la clave es inválida) |
| `idempotency_key_mismatch` | 422 | La misma `Idempotency-Key` se usó con otro método, ruta o cuerpo |
| `idempotency_key_in_progress` | 409 | La primera petición con esa `Idempotency-Key` sigue ejecutándose |
| `server_error` | 500 | Error interno |
| `upstream_error` | 502/504 | Pasarela o servicio externo |
| `service_unavailable` | 503 | Servicio no disponible |
| `http_error` | otros | Cualquier otro status sin código propio |

Si no se indica, el `code` se deduce del `type` histórico y, en su defecto, del
status (`App\Enums\ErrorCode::fromType()` / `fromStatus()`). Cada `case` del enum
debe aparecer en esta tabla (lo verifica `tests/Unit/ErrorCodeDocsTest.php`).

### Códigos propios del boilerplate (strings, fuera del enum)

`public_sharing_disabled` (403, compartir archivos públicos apagado), `sequence_exhausted` (409, secuencia en su máximo),
`plan_limit_unknown` (422, clave de límite no declarada), `plan_limit_reached` (403), `feature_unknown` (404).

Archivos (`docs/files.md`): `storage_quota_exceeded` (403, cuota de almacenamiento del plan; `limit_bytes`, `used_bytes`, `adding_bytes`),
`file_too_large_for_plan` (413, `max_file_mb` del plan; `limit_bytes`), `direct_upload_unsupported` (422, el disco no firma subidas),
`upload_object_missing` (422, aún no hay objeto), `upload_size_mismatch`, `upload_mime_mismatch`, `upload_checksum_mismatch` (422, el objeto
se borra y la intención se descarta), `upload_intent_expired` y `upload_intent_aborted` (422).

### Códigos propios de un proyecto

El enum es la base del boilerplate; un proyecto añade los suyos como string en su
excepción, sin tocar el enum:

```php
class InvoiceAlreadyPaidException extends ApiException
{
    public function __construct()
    {
        parent::__construct('La factura ya está pagada.', 409);
    }

    public function errorCode(): ErrorCode|string
    {
        return 'invoice_already_paid';
    }
}
```

En controllers: `$this->sendError('...', 409, code: 'invoice_already_paid')`.
Documenta cada código propio en la doc de la API del proyecto.

Códigos de string que ya trae el boilerplate (webhooks entrantes, `docs/integrations.md` §7):

| `code` | HTTP | Significado |
|---|---|---|
| `webhook_provider_unknown` | 404 | El `{provider}` no está registrado |
| `webhook_not_configured` | 500 | El proveedor no tiene secreto configurado |
| `webhook_signature_missing` | 401 | Falta la cabecera de firma (o su timestamp) |
| `webhook_signature_invalid` | 401 | La firma no coincide con el cuerpo |
| `webhook_timestamp_expired` | 401 | Timestamp fuera de la ventana anti-replay |
| `webhook_payload_malformed` | 400 | Cuerpo no JSON o sin id de entrega |

## 2. Errores del handler global (`bootstrap/app.php`)

Toda excepción no capturada la convierte el handler. Llevan `meta` con
`version`, `timestamp` y `request_id` (el `X-Trace-ID`/`X-Request-ID` si llegó).

### Validación (422)

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "meta": { "version": "1.0", "timestamp": "2026-05-22T16:40:46+00:00", "request_id": "uuid" },
  "type": "validation_error",
  "code": "validation_failed",
  "request_id": "uuid",
  "errors": { "login": ["El email o username es obligatorio."] }
}
```

(Los mensajes de cada campo salen en el idioma de la aplicación.)

### No autenticado (401): token faltante, expirado, inválido o revocado

```json
{ "success": false, "message": "No autenticado", "code": "unauthenticated", "request_id": "uuid",
  "meta": { ... }, "type": "unauthorized" }
```

Un JWT inválido responde `"Token inválido o expirado"` con el mismo formato y
`code` `token_invalid` (o `token_expired` si expiró).

### No autorizado (403)

Falta de permiso en `permission:`/`role:` (Spatie):

```json
{
  "type": "forbidden",
  "title": "No tienes permiso para realizar esta acción",
  "status": 403,
  "detail": "No tienes permiso para realizar esta acción",
  "instance": "users",
  "required_permissions": ["users.read"],
  "code": "forbidden",
  "request_id": "uuid",
  "meta": { ... }
}
```

Una policy que deniega (`AuthorizationException`) responde
`{ "success": false, "message": "No autorizado", "code": "forbidden", "request_id": "uuid", "meta": {...}, "type": "forbidden" }`.

### RFC 7807 (404, 422 de negocio, 5xx y excepciones de API)

Estructura `type`, `title`, `status`, `detail`, `instance` (+ extensiones) y `meta`:

```json
{
  "type": "not_found",
  "title": "Endpoint not found.",
  "status": 404,
  "detail": "The requested endpoint does not exist.",
  "instance": "users/999",
  "path": "users/999",
  "code": "not_found",
  "request_id": "uuid",
  "meta": { ... }
}
```

- `BusinessLogicException` → 422, `type: "business_logic_error"`.
- Error interno → 500, `type: "internal_server_error"`, título
  `An internal server error occurred.`; con `APP_DEBUG=true` incluye
  `exception`, `file`, `line` y `trace`.

### Rate limit (429)

```json
{ "success": false, "message": "...", "type": "rate_limit_exceeded", "code": "rate_limited", "request_id": "uuid", "meta": { ... } }
```

con cabecera `Retry-After` cuando se conoce.

## 3. `App\Helpers\ApiResponse` (middlewares y handler)

`success`, `created`, `paginated`, `error`, `validation`, `notFound`,
`unauthorized`, `forbidden`, `rateLimited`, `serverError` y `rfc7807`. Aquí sí
va `meta` (`version`, `timestamp`, `request_id`) y, en errores, `code` y `request_id`. **Los controllers no lo usan**:
usan los métodos de la base.

---

## Ejemplos por Endpoint

### Login (`POST /auth/login`)

**Request:**
```json
{ "login": "elmerson", "password": "1234" }
```
`login` es el email o el username.

**Éxito (200):**
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": { "id": 1, "name": "Elmer Merino", "email": "elmer@apygg.com" },
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "token_type": "bearer",
    "expires_in": 900
  }
}
```

**Error (401):**
```json
{ "success": false, "message": "Credenciales inválidas. Intentos restantes: 4" }
```

La cuenta bloqueada por intentos o en lista negra responde por el handler
(RFC 7807).

### Registro (`POST /auth/register`)

Éxito (201): mismo `data` que el login, con
`"message": "Usuario registrado exitosamente"`. Error 422: validación.

### Listado de usuarios (`GET /users`)

```json
{
  "success": true,
  "data": [
    {
      "id": 9,
      "name": "Carlos Mendoza",
      "username": "73988785",
      "email": "carlos@example.com",
      "roles": [ { "id": 2, "name": "manager", "display_name": "Manager" } ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 9, "last_page": 1, "from": 1, "to": 9 },
  "links": { "first": "...", "last": "...", "prev": null, "next": null }
}
```

**Notas:**
- `roles` siempre es un array, aunque esté vacío (`[]`).
- `created_at` y `updated_at` en formato ISO 8601.
- La lista exacta de campos la define `UserResource`.

---

## Códigos HTTP Resumen

| Código | Nombre | Cuándo Usar |
|--------|--------|-------------|
| 200 | OK | GET, PUT, PATCH, DELETE exitoso |
| 201 | Created | POST - recurso creado |
| 400 | Bad Request | Solicitud malformada |
| 401 | Unauthorized | No autenticado |
| 403 | Forbidden | No autorizado |
| 404 | Not Found | Recurso no existe |
| 422 | Unprocessable Entity | Validación fallida |
| 429 | Too Many Requests | Rate limit |
| 500 | Internal Server Error | Error del servidor |

---

## Headers Comunes

Todas las respuestas incluyen:

- `Content-Type: application/json`
- `X-Trace-ID` - ID de trace para debugging
- `X-RateLimit-Limit` - límite de requests
- `X-RateLimit-Remaining` - requests restantes
- `X-RateLimit-Reset` - tiempo hasta reset

Respuestas con CORS incluyen:
- `Access-Control-Allow-Origin`
- `Access-Control-Allow-Credentials`
- `Access-Control-Expose-Headers`

---

## Referencias

- [RFC 7807 - Problem Details for HTTP APIs](https://tools.ietf.org/html/rfc7807)
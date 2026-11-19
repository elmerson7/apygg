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
{ "success": false, "message": "Credenciales inválidas. Intentos restantes: 4" }
```

`sendError` añade `"errors": { ... }` solo si se le pasan.

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
  "errors": { "login": ["El email o username es obligatorio."] }
}
```

(Los mensajes de cada campo salen en el idioma de la aplicación.)

### No autenticado (401): token faltante, expirado, inválido o revocado

```json
{ "success": false, "message": "No autenticado", "meta": { ... }, "type": "unauthorized" }
```

Un JWT inválido responde `"Token inválido o expirado"` con el mismo formato.

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
  "meta": { ... }
}
```

Una policy que deniega (`AuthorizationException`) responde
`{ "success": false, "message": "No autorizado", "meta": {...}, "type": "forbidden" }`.

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
  "meta": { ... }
}
```

- `BusinessLogicException` → 422, `type: "business_logic_error"`.
- Error interno → 500, `type: "internal_server_error"`, título
  `An internal server error occurred.`; con `APP_DEBUG=true` incluye
  `exception`, `file`, `line` y `trace`.

### Rate limit (429)

```json
{ "success": false, "message": "...", "type": "rate_limit_exceeded", "meta": { ... } }
```

con cabecera `Retry-After` cuando se conoce.

## 3. `App\Helpers\ApiResponse` (middlewares y handler)

`success`, `created`, `paginated`, `error`, `validation`, `notFound`,
`unauthorized`, `forbidden`, `rateLimited`, `serverError` y `rfc7807`. Aquí sí
va `meta` (`version`, `timestamp`, `request_id`). **Los controllers no lo usan**:
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
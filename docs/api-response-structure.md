# Respuestas API

## Estructura General

Todas las respuestas siguen un formato consistente:

```json
{
  "success": true|false,
  "message": "Mensaje legible",
  "meta": {
    "version": "1.0",
    "timestamp": "2026-05-22T16:40:46+00:00",
    "request_id": "uuid",
    "execution_time_ms": 2
  },
  "data": { ... }          // solo en éxito (200-201)
  "errors": { ... }        // solo en error
  "type": "tipo_error"     // solo en error (RFC 7807)
}
```

---

## Tipos de Respuesta

### Éxito (200-201)

**Estructura estándar — TODAS las respuestas exitosas siguen este formato:**

```json
{
  "success": true,
  "data": [ ... ]      // array para listas, objeto para单个
}
```

**Con paginación:**

```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 9,
    "last_page": 1,
    "from": 1,
    "to": 9
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  }
}
```

**Sin paginación (recurso individual):**

```json
{
  "success": true,
  "data": { "id": 1, "name": "Ejemplo" }
}
```

**Regla fija:** el contenido del recurso SIEMPRE va dentro de `data`. Nunca se devuelven propiedades sueltas al mismo nivel que `success`/`data`.

| Código | Uso |
|--------|-----|
| 200 | GET, PUT, PATCH, DELETE exitoso |
| 201 | POST (creación) |

---

### Error de Validación (422)

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "meta": {
    "version": "1.0",
    "timestamp": "2026-05-22T16:40:46+00:00",
    "request_id": "uuid",
    "execution_time_ms": 2
  },
  "type": "validation_error",
  "errors": {
    "login": ["El email o username es obligatorio."],
    "password": ["La contraseña debe tener al menos 8 caracteres."]
  }
}
```

**Causas comunes:**
- Datos faltantes o inválidos
- Email con formato incorrecto
- Contraseña muy corta

---

### No Autenticado (401)

```json
{
  "success": false,
  "message": "No autenticado",
  "meta": { ... },
  "type": "unauthorized"
}
```

**Causas comunes:**
- Token JWT faltante
- Token expirado
- Token inválido

---

### No Autorizado (403)

```json
{
  "success": false,
  "message": "No autorizado",
  "meta": { ... },
  "type": "forbidden"
}
```

**Causas comunes:**
- Usuario autenticado pero sin permisos
- Rol insuficiente

---

### No Encontrado (404)

```json
{
  "success": false,
  "message": "Endpoint not found.",
  "meta": { ... },
  "type": "not_found",
  "errors": {
    "path": "/api/users/999"
  }
}
```

---

### Rate Limit Exceeded (429)

```json
{
  "success": false,
  "message": "Too many requests",
  "meta": { ... },
  "type": "rate_limit_exceeded"
}
```

---

### Error del Servidor (500)

```json
{
  "success": false,
  "message": "An internal server error occurred.",
  "meta": { ... },
  "type": "internal_server_error"
}
```

En entorno `APP_DEBUG=true`, incluye detalles del error.

---

## Ejemplos por Endpoint

### Login

**Request:**
```json
{
  "login": "elmerson",       // o email: "elmerson@test.com"
  "password": "1234"
}
```

**Éxito (200):**
```json
{
  "success": true,
  "message": "Login exitoso",
  "meta": { ... },
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
{
  "success": false,
  "message": "Credenciales inválidas. Intentos restantes: 4",
  "meta": { ... },
  "type": "unauthorized"
}
```

---

### Registro

**Request:**
```json
{
  "name": "Juan Perez",
  "email": "juan@test.com",
  "password": "password123"
}
```

**Éxito (201):** Mismo que login

**Error (422):** Validación de campos

---

### Listado de usuarios (GET /users)

```json
{
  "success": true,
  "data": [
    {
      "id": 9,
      "created_at": "2026-05-24T01:54:21+00:00",
      "updated_at": "2026-05-24T01:54:21+00:00",
      "name": "Carlos Mendoza",
      "first_name": "Carlos",
      "last_name": "Mendoza",
      "username": "73988785",
      "email": "admin@rebagliati.edu.pe",
      "email_verified_at": null,
      "identity_document": "123423423",
      "roles": [
        {
          "id": 2,
          "name": "ejecutivo-cuentas",
          "display_name": "Ejecutivo de Cuentas"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 9,
    "last_page": 1,
    "from": 1,
    "to": 9
  }
}
```

**Notas:**
- `roles` siempre es un array, aunque esté vacío (`[]`)
- Cada rol tiene `id` (int), `name` (string), `display_name` (string)
- El usuario siempre tiene al menos 1 rol
- `first_name` y `last_name` vienen del perfil (`user_profiles`)
- `created_at` y `updated_at` en formato ISO 8601

---

## Códigos HTTP Resumen

| Código | Nombre | Cuándo Usar |
|--------|--------|-------------|
| 200 | OK | GET, PUT, PATCH, DELETE exitoso |
| 201 | Created | POST - recurso creado |
| 204 | No Content | DELETE exitoso (sin body) |
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
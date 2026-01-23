# Consideraciones para Proyecto API-Only

## 1. Autenticación

### JWT vs API Keys

**JWT (JSON Web Tokens)**:
- ✅ **Ideal para**: Usuarios autenticados, aplicaciones móviles/web
- ✅ **Ventajas**: Stateless, escalable, contiene información del usuario
- ✅ **Uso**: Login → recibe token → lo envías en cada request
- ✅ **Ya implementado**: `php-open-source-saver/jwt-auth`

**API Keys**:
- ✅ **Ideal para**: Integraciones de terceros, servicios, bots
- ✅ **Ventajas**: Más simple para servicios automatizados, no expira (o expiración muy larga)
- ✅ **Uso**: Cliente envía `X-API-Key: tu-api-key` en cada request
- ✅ **Recomendación**: Implementar ambos (JWT para usuarios, API Keys para servicios)

**Estrategia recomendada**:
- **JWT**: Para usuarios finales (login/registro)
- **API Keys**: Para integraciones de terceros (ya tienes tabla `api_keys`)

### Sessions

**¿Se usan sessions en API-only?**

❌ **NO**, si es API stateless:
- Las APIs RESTful son stateless por diseño
- Cada request debe contener toda la información necesaria (token JWT)
- No se guarda estado en el servidor

✅ **SÍ**, solo si necesitas:
- WebSockets con autenticación por sesión
- Laravel Reverb (ya incluido) puede usar sessions para WebSockets
- Pero para REST API pura: **NO usar sessions**

**Recomendación**: 
- Mantener `SESSION_DRIVER=redis` por si acaso necesitas WebSockets más adelante
- Pero las rutas API REST no deben usar sessions

### password_reset_tokens

**¿Para qué sirve?**

Es la tabla que Laravel usa para:
- Generar tokens únicos cuando un usuario solicita reset de contraseña
- Validar que el token es válido y no ha expirado
- Evitar reutilización de tokens

**¿Se usa en API-only?**

✅ **SÍ**, si implementas:
- Endpoint `POST /api/v1/auth/forgot-password` (envía email con token)
- Endpoint `POST /api/v1/auth/reset-password` (valida token y cambia contraseña)

**Flujo típico**:
1. Usuario solicita reset → se crea registro en `password_reset_tokens`
2. Se envía email con link/token
3. Usuario envía nuevo password + token → se valida y se cambia contraseña
4. Token se elimina (no reutilizable)

**Recomendación**: Mantener la tabla, es útil para API también.

### Stateless

**¿Qué es stateless?**

**Stateless** = Sin estado en el servidor

**Características**:
- Cada request HTTP es independiente
- El servidor NO guarda información entre requests
- Toda la información necesaria va en el request (headers, body, token)
- Escalable horizontalmente (puedes tener múltiples servidores)

**Ejemplo Stateless (JWT)**:
```
Request 1: POST /api/login → Response: { token: "abc123" }
Request 2: GET /api/users → Headers: { Authorization: Bearer abc123 }
Request 3: GET /api/posts → Headers: { Authorization: Bearer abc123 }
```
El servidor NO recuerda que hiciste login, solo valida el token en cada request.

**Ejemplo Stateful (Sessions)**:
```
Request 1: POST /login → Response: Set-Cookie: session_id=xyz
Request 2: GET /users → Cookie: session_id=xyz → Servidor busca sesión en Redis
Request 3: GET /posts → Cookie: session_id=xyz → Servidor busca sesión en Redis
```
El servidor guarda estado (la sesión) y lo busca en cada request.

**Recomendación para API**: **Stateless con JWT** ✅

## 2. Middleware Críticos

### CORS (Cross-Origin Resource Sharing)

**Documentación**: Ver `docs/cors-configuration.md`

**Configuración**:
- Ya configurado en `bootstrap/app.php` con `HandleCors`
- Variables de entorno: `ALLOWED_ORIGINS` (whitelist única para CORS y reset password), `CORS_MAX_AGE`, `CORS_SUPPORTS_CREDENTIALS`
- **Nota**: `ALLOWED_ORIGINS` se usa para validar `reset_url` en recuperación de contraseña (Fase 5.4) y se usará para CORS en la Fase 10

**Importante**:
- En desarrollo: `*` (todos los orígenes) o lista específica en `ALLOWED_ORIGINS`
- En producción: Solo orígenes específicos en `ALLOWED_ORIGINS` (nunca `*`)

### ForceJsonResponse

**Propósito**: Asegurar que todas las respuestas sean JSON

**Implementado**: `app/Http/Middleware/ForceJsonResponse.php`

**Funciona**:
- Fuerza `Accept: application/json` en requests
- Fuerza `Content-Type: application/json` en responses
- Asegura formato consistente en toda la API

### Otros Middleware Recomendados

1. **TraceIdMiddleware**: Agregar `X-Trace-ID` único a cada request
2. **RateLimitMiddleware**: Limitar requests por IP/usuario
3. **SecurityLoggerMiddleware**: Log de intentos de acceso sospechosos
4. **IdempotencyMiddleware**: Evitar duplicación de requests (POST/PUT)

## 3. Configuración Actual

### Rutas

✅ **`routes/api.php`**: Creado con prefijo `/api/v1`
✅ **`routes/web.php`**: Comentado (no se carga)
✅ **`bootstrap/app.php`**: Configurado solo para API

### Middleware

✅ **CORS**: `HandleCors` configurado
✅ **ForceJsonResponse**: Creado y configurado
✅ **JWT Auth**: `php-open-source-saver/jwt-auth` instalado

### Base de Datos

✅ **Sessions**: Tabla existe pero NO se usa en API REST
✅ **password_reset_tokens**: Tabla existe, útil para reset de contraseña
✅ **api_keys**: Tabla existe para API Keys de servicios

## 4. Próximos Pasos

1. ✅ Configurar `routes/api.php` con estructura modular
2. ⏳ Crear controladores de autenticación (login, registro, reset password)
3. ⏳ Implementar middleware de TraceId
4. ⏳ Implementar middleware de RateLimit
5. ⏳ Configurar respuestas de error consistentes (JSON)
6. ⏳ Documentar endpoints con Scramble

## 5. Tablas Innecesarias para API-Only

**Mantener**:
- ✅ `users` - Necesaria
- ✅ `password_reset_tokens` - Útil para reset de contraseña
- ✅ `sessions` - Útil si usas WebSockets (Laravel Reverb)
- ✅ `api_keys` - Necesaria para API Keys
- ✅ `jwt_blacklist` - Necesaria para logout JWT

**Considerar eliminar** (si NO usas WebSockets):
- ⚠️ `sessions` - Solo si NO usas Laravel Reverb

**Recomendación**: Mantener `sessions` por si acaso necesitas WebSockets más adelante.

# Configuración de CORS

## Descripción

CORS (Cross-Origin Resource Sharing) permite que aplicaciones web en diferentes dominios accedan a recursos de la API. Esta configuración controla qué orígenes, métodos y headers están permitidos.

## Configuración

### Variables de Entorno

```env
# Orígenes permitidos (separados por comas)
# Esta variable se usa para CORS y validación de reset_url en recuperación de contraseña
ALLOWED_ORIGINS=https://example.com,https://www.example.com,localhost:8080,panel.tudominio.com

# Tiempo de cache para preflight requests (segundos)
CORS_MAX_AGE=3600

# Permitir credenciales (cookies, headers de autorización)
CORS_SUPPORTS_CREDENTIALS=true
```

**Nota**: La variable `ALLOWED_ORIGINS` se usa para ambos propósitos:
- ✅ Validación de `reset_url` en recuperación de contraseña (Fase 5.4 - Implementado)
- ✅ Configuración de CORS (Fase 10 - Implementado)

**Variable única**: Solo se usa `ALLOWED_ORIGINS` para ambos casos. No se usa `CORS_ALLOWED_ORIGINS`.

### Configuración por Entorno

#### Desarrollo (local, dev, testing)
- **Orígenes**: Lista específica en `ALLOWED_ORIGINS` (ej: `http://localhost:5173,http://localhost:3000`)
- **Credenciales**: `true`
- **Max Age**: 3600 segundos

#### Staging
- **Orígenes**: Lista específica en `ALLOWED_ORIGINS` (ej: `https://staging.example.com`)
- **Credenciales**: `true` (configurable)
- **Max Age**: 3600 segundos (configurable)

#### Producción
- **Orígenes**: Lista específica en `ALLOWED_ORIGINS` (ej: `https://example.com,https://www.example.com`)
- **Credenciales**: `true` (configurable)
- **Max Age**: 3600 segundos (configurable)

⚠️ **IMPORTANTE**: En todos los entornos (dev, staging, prod) se debe usar `ALLOWED_ORIGINS` con dominios específicos. NO usar `*` para garantizar que la configuración funcione correctamente.

## Configuración Detallada

### Orígenes Permitidos

**Variable centralizada**: `ALLOWED_ORIGINS`

Esta variable se configura en `config/app.php` y se usa para múltiples propósitos:

```php
// config/app.php
'allowed_origins' => array_filter(
    array_map('trim', explode(',', env('ALLOWED_ORIGINS', '')))
),
```

Configuración en `.env`:
```env
# Soporta URLs completas, hosts con puertos, o solo hosts
ALLOWED_ORIGINS="https://example.com,https://www.example.com,localhost:8080,panel.tudominio.com,https://192.168.50.178:8081"
```

**Formato soportado**:
- URLs completas: `https://app.tudominio.com`
- Hosts con puertos: `localhost:8080`, `192.168.50.178:8081`
- Solo hosts: `panel.tudominio.com`
- URLs con trailing slash: `http://localhost:8080/` (se normaliza)

**Variable única**: Solo se usa `ALLOWED_ORIGINS` para CORS y reset password.

**Nota**: ✅ La configuración completa de CORS usando `ALLOWED_ORIGINS` está implementada. Se usa `ALLOWED_ORIGINS` en todos los entornos (dev, staging, prod) para garantizar que funcione correctamente.

### Métodos HTTP Permitidos

Por defecto, se permiten todos los métodos comunes:
- GET
- POST
- PUT
- PATCH
- DELETE
- OPTIONS
- HEAD

### Headers Permitidos

Headers que el cliente puede enviar:
- `Content-Type`
- `Authorization`
- `X-Requested-With`
- `X-Trace-ID`
- `X-API-Version`
- `Accept`
- `Origin`
- `X-CSRF-TOKEN`

### Headers Expuestos

Headers que el cliente puede leer en la respuesta:
- `X-Trace-ID`
- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`
- `X-API-Version`

## Uso

### Verificar Configuración

```bash
php artisan cors:test
```

Para probar un origen específico:
```bash
php artisan cors:test http://localhost:5173
```

También puedes usar el script de prueba manual:
```bash
./tests/cors-test.sh [URL_API] [ORIGIN]
# Ejemplo:
./tests/cors-test.sh http://localhost:8010 http://localhost:5173
```

### Ejemplo de Request desde JavaScript

```javascript
// Request simple
fetch('https://api.example.com/api/users', {
    method: 'GET',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer token',
    },
    credentials: 'include', // Incluir cookies si supports_credentials=true
})
.then(response => response.json())
.then(data => console.log(data));
```

### Ejemplo de Request con Preflight

Para métodos como PUT, DELETE, PATCH, el navegador envía primero un request OPTIONS (preflight):

```javascript
fetch('https://api.example.com/api/users/1', {
    method: 'PUT',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer token',
    },
    body: JSON.stringify({ name: 'John' }),
    credentials: 'include',
})
.then(response => response.json())
.then(data => console.log(data));
```

## Seguridad

### Mejores Prácticas

1. **Nunca uses `*` en producción**
   - Es un riesgo de seguridad
   - Los navegadores bloquean credenciales con `*`

2. **Especifica orígenes exactos**
   ```env
   # ✅ Correcto
   ALLOWED_ORIGINS=https://example.com,https://www.example.com,localhost:8080
   
   # ❌ Incorrecto
   ALLOWED_ORIGINS=*
   ```

3. **Usa HTTPS en producción**
   ```env
   # ✅ Correcto
   ALLOWED_ORIGINS=https://example.com,https://app.example.com
   
   # ❌ Incorrecto (solo para desarrollo)
   ALLOWED_ORIGINS=http://example.com
   ```

4. **No incluyas localhost en producción**
   - Solo para desarrollo
   - Elimina antes de desplegar

5. **Revisa configuración regularmente**
   ```bash
   php artisan cors:check
   ```

### Validaciones Automáticas

El comando `cors:test` verifica:
- ✅ Orígenes permitidos configurados
- ✅ No usar `*` en producción
- ✅ No usar wildcards con credenciales
- ✅ Formato correcto de URLs
- ✅ No usar HTTP en producción (solo para desarrollo)

## Troubleshooting

### Error: "Access-Control-Allow-Origin header not present"

**Causa**: El origen no está en la lista de permitidos.

**Solución**:
1. Verificar `ALLOWED_ORIGINS` en `.env`
2. Ejecutar `php artisan config:clear`
3. Verificar con `php artisan cors:test`

### Error: "Credentials flag is true, but Access-Control-Allow-Origin is *"

**Causa**: No se pueden usar credenciales con origen `*`.

**Solución**:
1. Especificar orígenes exactos en `ALLOWED_ORIGINS`
2. O cambiar `CORS_SUPPORTS_CREDENTIALS=false`

### Preflight OPTIONS falla

**Causa**: El middleware no está registrado correctamente.

**Solución**:
1. Verificar que `CorsMiddleware` esté en `bootstrap/app.php`
2. Verificar que esté antes de otros middleware (debe ser el primero)
3. Ejecutar `php artisan config:clear`

### Headers personalizados no funcionan

**Causa**: El header no está en `allowed_headers`.

**Solución**:
1. Agregar el header a `config/cors.php` → `allowed_headers`
2. Ejecutar `php artisan config:clear`

## Testing

### Probar CORS localmente

```bash
# Iniciar servidor
php artisan serve

# En otra terminal, probar con curl
curl -H "Origin: http://localhost:3000" \
     -H "Access-Control-Request-Method: GET" \
     -H "Access-Control-Request-Headers: Content-Type" \
     -X OPTIONS \
     http://localhost:8000/api/test
```

### Probar desde navegador

Abre la consola del navegador y ejecuta:

```javascript
fetch('http://localhost:8000/api/test', {
    method: 'GET',
    headers: {
        'Content-Type': 'application/json',
    },
})
.then(r => r.json())
.then(console.log)
.catch(console.error);
```

## Referencias

- [MDN: CORS](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)
- [Laravel CORS Documentation](https://laravel.com/docs/cors)

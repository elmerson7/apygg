# Configuración de CORS

## Descripción

CORS (Cross-Origin Resource Sharing) permite que aplicaciones web en diferentes dominios accedan a recursos de la API. Esta configuración controla qué orígenes, métodos y headers están permitidos.

## Configuración

### Variables de Entorno

```env
# Orígenes permitidos (separados por comas)
CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com

# Tiempo de cache para preflight requests (segundos)
CORS_MAX_AGE=3600

# Permitir credenciales (cookies, headers de autorización)
CORS_SUPPORTS_CREDENTIALS=true
```

### Configuración por Entorno

#### Desarrollo (local, dev, testing)
- **Orígenes**: `*` (todos permitidos)
- **Credenciales**: `true`
- **Max Age**: 3600 segundos

#### Producción
- **Orígenes**: Debe configurarse explícitamente en `CORS_ALLOWED_ORIGINS`
- **Credenciales**: `true` (configurable)
- **Max Age**: 3600 segundos (configurable)

⚠️ **IMPORTANTE**: En producción, nunca uses `*` como origen. Siempre especifica dominios exactos.

## Configuración Detallada

### Orígenes Permitidos

```php
// config/cors.php
'allowed_origins' => [
    'https://example.com',
    'https://www.example.com',
    'https://app.example.com',
],
```

O desde variable de entorno:
```env
CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com
```

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
php artisan cors:check
```

Con sugerencias de corrección:
```bash
php artisan cors:check --fix
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
   CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com
   
   # ❌ Incorrecto
   CORS_ALLOWED_ORIGINS=*
   ```

3. **Usa HTTPS en producción**
   ```env
   # ✅ Correcto
   CORS_ALLOWED_ORIGINS=https://example.com
   
   # ❌ Incorrecto
   CORS_ALLOWED_ORIGINS=http://example.com
   ```

4. **No incluyas localhost en producción**
   - Solo para desarrollo
   - Elimina antes de desplegar

5. **Revisa configuración regularmente**
   ```bash
   php artisan cors:check
   ```

### Validaciones Automáticas

El comando `cors:check` verifica:
- ✅ No usar `*` en producción
- ✅ No usar wildcards con credenciales
- ✅ Formato correcto de URLs
- ✅ No usar HTTP en producción
- ✅ No incluir localhost en producción

## Troubleshooting

### Error: "Access-Control-Allow-Origin header not present"

**Causa**: El origen no está en la lista de permitidos.

**Solución**:
1. Verificar `CORS_ALLOWED_ORIGINS` en `.env`
2. Ejecutar `php artisan config:clear`
3. Verificar con `php artisan cors:check`

### Error: "Credentials flag is true, but Access-Control-Allow-Origin is *"

**Causa**: No se pueden usar credenciales con origen `*`.

**Solución**:
1. Especificar orígenes exactos en `CORS_ALLOWED_ORIGINS`
2. O cambiar `CORS_SUPPORTS_CREDENTIALS=false`

### Preflight OPTIONS falla

**Causa**: El middleware no está registrado correctamente.

**Solución**:
1. Verificar que `HandleCors` esté en `bootstrap/app.php`
2. Verificar que esté antes de otros middleware
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

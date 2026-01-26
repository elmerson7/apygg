# Configuración de FrankenPHP

## Descripción

FrankenPHP es el servidor HTTP integrado en Laravel Octane que proporciona alto rendimiento y soporte nativo para características modernas como HTTP/2, HTTP/3, compresión automática y SSL con Let's Encrypt.

## Características Configuradas

### ✅ 1. Puerto Configurable

**Objetivo**: Soporte para PaaS (Railway, Cloud Run) y despliegues tradicionales.

**Implementación**:
- Puerto dinámico desde variable `PORT` del entorno
- Valor por defecto: `8000` si no se define
- Funciona en todos los escenarios de despliegue

**Variables**:
```env
PORT=8000  # Fijo para VPS/Docker, dinámico para PaaS
```

**Código** (`docker/app/entrypoint.sh`):
```bash
PORT=${PORT:-8000}  # Usa PORT si existe, sino 8000
php artisan octane:start --port=$PORT
```

---

### ✅ 2. SSL Automático con Let's Encrypt (Opcional)

**Objetivo**: HTTPS automático cuando no hay proxy externo.

**Implementación**:
- Caddy (incluido en FrankenPHP) maneja certificados automáticamente
- Renovación automática de certificados
- Redirección HTTP → HTTPS

**Variables**:
```env
USE_FRANKENPHP_SSL=true   # Habilitar SSL en FrankenPHP
SERVER_NAME=api.tudominio.com  # Dominio para certificado
```

**Configuración** (`docker/app/Caddyfile`):
```caddyfile
api.tudominio.com {
    encode zstd gzip
    reverse_proxy localhost:8000
}
```

**Cuándo usar**:
- ✅ Sin proxy externo (Nginx/Caddy)
- ✅ Despliegue simple en VPS
- ❌ Con proxy externo (dejar que el proxy maneje SSL)

---

### ✅ 3. Compresión HTTP Habilitada

**Objetivo**: Reducir tamaño de respuestas y mejorar rendimiento.

**Implementación**:
- **Con Caddy** (`USE_FRANKENPHP_SSL=true`): Compresión automática con `zstd` y `gzip` configurada en Caddyfile
- **Sin Caddy** (desarrollo/proxy externo): Compresión mediante `CompressionMiddleware` en Laravel
- Prioriza `zstd` (mejor compresión), fallback a `gzip`
- Solo comprime respuestas mayores a 1KB

**Headers**:
```
Content-Encoding: zstd  # o gzip
Vary: Accept-Encoding
```

**Beneficios**:
- Respuestas 60-80% más pequeñas
- Menor uso de ancho de banda
- Mejor tiempo de carga

**Nota**: En desarrollo, la compresión funciona mediante middleware de Laravel. En producción con Caddy, la compresión es más eficiente al nivel del servidor.

---

### ✅ 4. Rate Limiting

**Objetivo**: Protección contra abuso a nivel de aplicación.

**Estado**: Ya implementado en Laravel (`AdaptiveRateLimitingMiddleware`)

**Configuración**: `config/rate-limiting.php`

**Límites**:
- Auth endpoints: 5 req/min
- Lectura: 60 req/min
- Escritura: 30 req/min
- Admin: 10 req/min

---

## Flujos de Despliegue

### Escenario 1: VPS/Docker con Proxy Externo (Recomendado)

```
Internet → Nginx/Caddy (SSL) → FrankenPHP (HTTP interno:8000)
```

**Configuración**:
```env
PORT=8000
USE_FRANKENPHP_SSL=false
```

**Ventajas**:
- ✅ Más control sobre SSL
- ✅ Mejor para múltiples servicios
- ✅ Fácil mantenimiento

---

### Escenario 2: VPS/Docker sin Proxy (SSL en FrankenPHP)

```
Internet → FrankenPHP/Caddy (SSL) → Laravel App
```

**Configuración**:
```env
PORT=8000
USE_FRANKENPHP_SSL=true
SERVER_NAME=api.tudominio.com
```

**Ventajas**:
- ✅ Configuración simple
- ✅ SSL automático
- ✅ Sin dependencias externas

---

### Escenario 3: PaaS (Railway, Cloud Run)

```
Internet → PaaS Load Balancer → FrankenPHP (puerto dinámico)
```

**Configuración**:
```env
PORT=  # Se asigna automáticamente por PaaS
USE_FRANKENPHP_SSL=false  # PaaS maneja SSL
```

**Ventajas**:
- ✅ Escalado automático
- ✅ SSL gestionado por PaaS
- ✅ Sin gestión de servidor

---

### Escenario 4: Kubernetes

```
Internet → Ingress Controller (SSL) → Pod (FrankenPHP:8000)
```

**Configuración**:
```env
PORT=8000  # Puerto interno del pod
USE_FRANKENPHP_SSL=false  # Ingress maneja SSL
```

**Ventajas**:
- ✅ Orquestación avanzada
- ✅ SSL en Ingress
- ✅ Escalado horizontal

---

## Variables de Entorno

### Desarrollo (`env/dev.env`)

```env
PORT=8000
USE_FRANKENPHP_SSL=false
SERVER_NAME=
```

### Producción (`env/prod.env`)

```env
# Opción A: Con proxy externo (recomendado)
PORT=8000
USE_FRANKENPHP_SSL=false
SERVER_NAME=

# Opción B: Sin proxy (SSL en FrankenPHP)
PORT=8000
USE_FRANKENPHP_SSL=true
SERVER_NAME=api.tudominio.com
```

---

## Archivos de Configuración

### 1. `docker/app/entrypoint.sh`
- Lógica de inicio del servidor
- Lectura de variables `PORT`, `USE_FRANKENPHP_SSL`, `SERVER_NAME`
- Decisión entre Octane directo o Caddy con SSL

### 2. `docker/app/Caddyfile`
- Configuración de Caddy para SSL
- Compresión HTTP
- Headers de seguridad
- Reverse proxy a FrankenPHP

### 3. `config/octane.php`
- Configuración de Laravel Octane
- `OCTANE_SERVER=frankenphp`
- `OCTANE_HTTPS` (se lee del entorno)

---

## Comandos Útiles

### Verificar configuración

```bash
# Ver puerto usado
docker compose exec app env | grep PORT

# Verificar SSL
docker compose exec app env | grep USE_FRANKENPHP_SSL

# Ver logs de Caddy (si SSL está habilitado)
docker compose logs app | grep caddy
```

### Probar compresión

```bash
# Verificar headers de compresión
curl -H "Accept-Encoding: gzip" -I http://localhost:8010/api/health

# Debe mostrar: Content-Encoding: gzip
```

---

## Troubleshooting

### Puerto ya en uso

```bash
# Cambiar puerto en docker-compose.yml
ports:
  - "8011:8000"  # Cambiar 8011 por otro puerto
```

### SSL no funciona

1. Verificar que `SERVER_NAME` esté configurado
2. Verificar que `USE_FRANKENPHP_SSL=true`
3. Verificar que el dominio apunta al servidor
4. Revisar logs: `docker compose logs app`

### Compresión no funciona

**Si usa Caddy (USE_FRANKENPHP_SSL=true)**:
1. Verificar que Caddyfile tenga `encode zstd gzip`
2. Verificar headers del cliente: `Accept-Encoding: gzip`
3. Probar con curl: `curl -H "Accept-Encoding: gzip" ...`

**Si NO usa Caddy (desarrollo o con proxy externo)**:
1. La compresión se maneja mediante `CompressionMiddleware` en Laravel
2. Verificar que el middleware esté registrado en `bootstrap/app.php`
3. Verificar que el cliente envíe `Accept-Encoding: gzip` o `Accept-Encoding: zstd`
4. La compresión solo se aplica a respuestas mayores a 1KB
5. Probar con curl: `curl -H "Accept-Encoding: gzip" -I http://localhost:8010/health`

---

## Notas Importantes

1. **Puerto dinámico**: Solo necesario para PaaS. En VPS/Docker usar puerto fijo.

2. **SSL**: Preferir proxy externo (Nginx/Caddy) para más control y flexibilidad.

3. **Compresión**: Ya está habilitada en Caddyfile. No requiere configuración adicional.

4. **Rate Limiting**: Ya implementado en Laravel. No requiere configuración adicional en FrankenPHP.

5. **Workers**: Se configuran automáticamente según CPU en producción (`--workers=auto`).

---

## Referencias

- [FrankenPHP Documentation](https://frankenphp.dev/)
- [Laravel Octane Documentation](https://laravel.com/docs/octane)
- [Caddy Documentation](https://caddyserver.com/docs/)
- [Let's Encrypt](https://letsencrypt.org/)

# Configuración de Redis

## Descripción

Redis está configurado como driver por defecto para:
- **Cache**: Almacenamiento de caché de la aplicación
- **Sesiones**: Almacenamiento de sesiones de usuario
- **Colas**: Procesamiento asíncrono de trabajos

## Configuración

### Bases de Datos Redis

Redis utiliza diferentes bases de datos (0-15) para separar datos:

- **Base 0 (default)**: Sesiones y conexión general
- **Base 1 (cache)**: Caché de la aplicación
- **Base 2 (queue)**: Colas de trabajos

### Colas con Prioridades

Se han configurado tres colas con diferentes prioridades:

1. **high**: Trabajos de alta prioridad (urgentes)
2. **default**: Trabajos de prioridad normal
3. **low**: Trabajos de baja prioridad (pueden esperar)

### Configuración de Reintentos

- **Max retries**: 3 intentos
- **Backoff algorithm**: `decorrelated_jitter` (backoff exponencial)
- **Backoff base**: 100ms
- **Backoff cap**: 1000ms
- **Timeout**: 60 segundos (`retry_after`)

## Uso

### Cache

```php
// Usar cache (automáticamente usa Redis)
Cache::put('key', 'value', 60); // 60 segundos
$value = Cache::get('key');
Cache::forget('key');
```

### Sesiones

Las sesiones se almacenan automáticamente en Redis. No se requiere código adicional.

### Colas

#### Enviar trabajo a cola por defecto

```php
dispatch(new MyJob());
// o
MyJob::dispatch();
```

#### Enviar trabajo a cola específica

```php
// Alta prioridad
MyJob::dispatch()->onQueue('high');

// Prioridad normal
MyJob::dispatch()->onQueue('default');

// Baja prioridad
MyJob::dispatch()->onQueue('low');
```

#### Usar conexión específica

```php
// Usar conexión redis-high
MyJob::dispatch()->onConnection('redis-high');

// Usar conexión redis-low
MyJob::dispatch()->onConnection('redis-low');
```

#### Procesar colas

```bash
# Procesar todas las colas (respeta prioridades)
php artisan queue:work redis

# Procesar cola específica
php artisan queue:work redis --queue=high,default,low

# Procesar solo cola de alta prioridad
php artisan queue:work redis --queue=high
```

## Pruebas

### Probar conectividad

```bash
php artisan redis:test
```

Este comando verifica:
- ✅ Conexión a Redis
- ✅ Funcionamiento de Cache
- ✅ Funcionamiento de Sesiones
- ✅ Configuración de Colas
- ✅ Colas con prioridades

### Probar manualmente

```bash
# Conectarse a Redis desde el contenedor
docker compose exec redis redis-cli

# Ver todas las bases de datos
INFO keyspace

# Ver claves en base de datos 0 (sesiones)
SELECT 0
KEYS *

# Ver claves en base de datos 1 (cache)
SELECT 1
KEYS *

# Ver claves en base de datos 2 (colas)
SELECT 2
KEYS *
```

## Variables de Entorno

Las siguientes variables controlan la configuración de Redis:

```env
# Redis Connection
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_CLIENT=phpredis

# Redis Databases
REDIS_DB=0          # Base de datos por defecto (sesiones)
REDIS_CACHE_DB=1    # Base de datos para cache
REDIS_QUEUE_DB=2    # Base de datos para colas

# Cache
CACHE_STORE=redis
REDIS_CACHE_CONNECTION=cache

# Sessions
SESSION_DRIVER=redis
SESSION_CONNECTION=default

# Queues
QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_RETRY_AFTER=60
```

## Troubleshooting

### Redis no se conecta

1. Verificar que el servicio Redis esté corriendo:
   ```bash
   docker compose ps redis
   ```

2. Verificar conectividad:
   ```bash
   docker compose exec redis redis-cli ping
   ```

3. Verificar variables de entorno:
   ```bash
   docker compose exec app env | grep REDIS
   ```

### Cache no funciona

1. Verificar que `CACHE_STORE=redis` en `.env`
2. Ejecutar `php artisan config:clear`
3. Probar con `php artisan redis:test`

### Colas no procesan

1. Verificar que `QUEUE_CONNECTION=redis` en `.env`
2. Verificar que el worker esté corriendo:
   ```bash
   php artisan queue:work redis
   ```
3. Verificar logs:
   ```bash
   tail -f storage/logs/laravel.log
   ```

## Notas

- Las colas con prioridades se procesan en orden: `high` → `default` → `low`
- El backoff exponencial ayuda a evitar saturación en caso de errores
- Los timeouts de 60 segundos son apropiados para la mayoría de trabajos
- Ajustar `retry_after` según la duración esperada de tus trabajos

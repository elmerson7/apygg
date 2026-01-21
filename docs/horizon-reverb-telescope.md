# Configuración de Horizon, Reverb y Telescope

## Descripción

- **Horizon**: Dashboard y gestor de colas de Laravel
- **Reverb**: Servidor WebSocket nativo de Laravel
- **Telescope**: Herramienta de debugging y observabilidad (solo desarrollo)

## Configuración en Docker

### Servicios Docker

Los servicios están configurados en `docker-compose.yml`:

#### Horizon
- **Puerto**: No expone puerto (solo interno)
- **Perfiles**: dev, staging, prod
- **Comando**: `php artisan horizon`
- **Dependencias**: Redis

#### Reverb
- **Puerto**: 8012:8080 (host:container)
- **Perfiles**: dev, staging, prod
- **Comando**: `php artisan reverb:start --host=0.0.0.0 --port=8080`
- **Dependencias**: Redis

#### Telescope
- **No requiere servicio separado**: Se ejecuta en el contenedor de la app
- **Dashboard**: `/telescope` (solo en desarrollo)
- **Habilitado**: Solo en entornos `local` y `dev`

### Iniciar Servicios

```bash
# Desarrollo
docker compose --profile dev up -d horizon reverb

# Producción
docker compose --profile prod up -d horizon-prod reverb-prod
```

## Variables de Entorno

### Desarrollo (env/dev.env)

```env
# Reverb
REVERB_HOST=localhost
REVERB_PORT=8012
REVERB_SCHEME=http
REVERB_APP_ID=apygg
REVERB_APP_KEY=apygg-key
REVERB_APP_SECRET=apygg-secret

# Horizon
HORIZON_PREFIX=apygg_horizon
HORIZON_REDIS_CONNECTION=default

# Telescope
TELESCOPE_ENABLED=true
```

### Producción (env/prod.env)

```env
# Reverb
REVERB_HOST=TU_DOMINIO.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_APP_ID=apygg
REVERB_APP_KEY=TU_REVERB_KEY
REVERB_APP_SECRET=TU_REVERB_SECRET

# Horizon
HORIZON_PREFIX=apygg_horizon
HORIZON_REDIS_CONNECTION=default

# Telescope (deshabilitado)
TELESCOPE_ENABLED=false
```

## Horizon

### Dashboard

Acceder al dashboard:
- **URL**: `http://localhost:8010/horizon` (desarrollo)
- **Autenticación**: Configurada en `config/horizon.php`

### Configuración

Archivo: `config/horizon.php`

- **Path**: `/horizon` (configurable)
- **Redis Connection**: `default` (configurable)
- **Prefix**: `apygg_horizon:` (configurable)

### Workers

Configuración de workers en `config/horizon.php`:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'processes' => 10,
            'tries' => 3,
        ],
    ],
],
```

### Comandos Útil

```bash
# Ver estado
php artisan horizon:status

# Pausar
php artisan horizon:pause

# Continuar
php artisan horizon:continue

# Terminar
php artisan horizon:terminate

# Limpiar métricas
php artisan horizon:clear
```

## Reverb

### Configuración

Archivo: `config/reverb.php`

- **Host**: `0.0.0.0` (interno), `localhost` (desarrollo), dominio (producción)
- **Port**: `8080` (interno), `8012` (host)
- **Scheme**: `http` (desarrollo), `https` (producción)

### Variables Requeridas

```env
REVERB_APP_ID=apygg
REVERB_APP_KEY=tu-key-segura
REVERB_APP_SECRET=tu-secret-seguro
REVERB_HOST=localhost  # o tu dominio en producción
REVERB_PORT=8012       # o 443 en producción
REVERB_SCHEME=http     # o https en producción
```

### Generar Credenciales

```bash
php artisan reverb:install
```

Este comando genera las credenciales y las agrega a `.env`.

### Broadcasting

Configurar en `config/broadcasting.php`:

```php
'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST'),
            'port' => env('REVERB_PORT', 443),
            'scheme' => env('REVERB_SCHEME', 'https'),
            'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
        ],
    ],
],
```

### Uso desde Frontend

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

## Telescope

### Dashboard

Acceder al dashboard:
- **URL**: `http://localhost:8010/telescope` (solo en desarrollo)
- **Autenticación**: Configurada en `config/telescope.php`

### Configuración

Archivo: `config/telescope.php`

- **Enabled**: Solo en `local` y `dev` (automático)
- **Path**: `/telescope` (configurable)
- **Driver**: `database` (almacena en BD)

### Watchers

Telescope monitorea:
- Requests
- Commands
- Jobs
- Queries
- Models
- Events
- Logs
- Dumps
- Cache
- Schedule
- Mail
- Notifications
- Exceptions
- Gates
- Views

### Deshabilitar en Producción

Telescope está automáticamente deshabilitado en producción:

```php
'enabled' => env('TELESCOPE_ENABLED', env('APP_ENV') === 'local' || env('APP_ENV') === 'dev'),
```

O explícitamente:

```env
TELESCOPE_ENABLED=false
```

## Troubleshooting

### Horizon no inicia

1. Verificar que Redis esté corriendo:
   ```bash
   docker compose ps redis
   ```

2. Verificar logs:
   ```bash
   docker compose logs horizon
   ```

3. Verificar configuración de Redis:
   ```bash
   docker compose exec app php artisan tinker
   >>> Redis::connection()->ping()
   ```

### Reverb no conecta

1. Verificar que el servicio esté corriendo:
   ```bash
   docker compose ps reverb
   ```

2. Verificar credenciales en `.env`:
   ```env
   REVERB_APP_ID=...
   REVERB_APP_KEY=...
   REVERB_APP_SECRET=...
   ```

3. Verificar conectividad:
   ```bash
   curl http://localhost:8012
   ```

### Telescope no muestra datos

1. Verificar que esté habilitado:
   ```bash
   docker compose exec app php artisan tinker
   >>> config('telescope.enabled')
   ```

2. Ejecutar migraciones:
   ```bash
   php artisan migrate
   ```

3. Limpiar cache:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

## Mejores Prácticas

1. **Horizon en producción**: Siempre usar Horizon para gestionar colas
2. **Reverb con HTTPS**: Usar HTTPS en producción
3. **Telescope solo en dev**: Nunca habilitar Telescope en producción
4. **Credenciales seguras**: Generar credenciales únicas para Reverb
5. **Monitoreo**: Revisar dashboard de Horizon regularmente
6. **Health checks**: Los servicios tienen health checks configurados

## Referencias

- [Laravel Horizon Documentation](https://laravel.com/docs/horizon)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)
- [Laravel Telescope Documentation](https://laravel.com/docs/telescope)

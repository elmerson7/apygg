# Estructura de Archivos de Logs Organizados por Fecha

## Descripción

Los archivos de logs están organizados en una estructura jerárquica por fecha: **año/mes/día** para facilitar la búsqueda y mantenimiento.

## Estructura de Directorios

```
storage/logs/
├── 2026/
│   ├── 01/
│   │   ├── 26/
│   │   │   ├── activity.log
│   │   │   ├── security.log
│   │   │   └── laravel.log
│   │   └── 27/
│   │       ├── activity.log
│   │       ├── security.log
│   │       └── laravel.log
│   └── 02/
│       └── ...
└── 2027/
    └── ...
```

## Archivos de Log

### `laravel.log`
- **Canal**: `single` o `daily`
- **Contenido**: Logs generales de la aplicación
- **Ubicación**: `storage/logs/YYYY/MM/DD/laravel.log`

### `activity.log`
- **Canal**: `activity`
- **Contenido**: Logs de actividad de usuarios (creaciones, actualizaciones, eliminaciones)
- **Ubicación**: `storage/logs/YYYY/MM/DD/activity.log`
- **Uso**: `LogService::logActivity()` o `Log::channel('activity')->info()`

### `security.log`
- **Canal**: `security`
- **Contenido**: Logs de eventos de seguridad (intentos de acceso, permisos denegados, actividad sospechosa)
- **Ubicación**: `storage/logs/YYYY/MM/DD/security.log`
- **Uso**: `LogService::logSecurity()` o `Log::channel('security')->warning()`

## Implementación Técnica

### Handler Personalizado

Se creó `DateOrganizedStreamHandler` que extiende `Monolog\Handler\StreamHandler`:

- **Ubicación**: `app/Logging/DateOrganizedStreamHandler.php`
- **Funcionalidad**: Crea automáticamente la estructura de directorios año/mes/día
- **Método estático**: `create()` para instanciación desde configuración

### Configuración

Los canales están configurados en:
- `config/logging.php`: Configuración de canales
- `app/Providers/AppServiceProvider.php`: Extensión de drivers personalizados

## Ventajas

1. **Organización**: Fácil encontrar logs de una fecha específica
2. **Mantenimiento**: Simpler limpiar logs antiguos (eliminar carpetas completas)
3. **Rendimiento**: Menos archivos en un solo directorio
4. **Backup**: Más fácil hacer backup por fecha
5. **Análisis**: Más simple analizar logs por período

## Permisos y Acceso desde WSL

Los logs se crean con permisos que permiten acceso tanto desde el contenedor Docker como desde WSL.

### Ajustar Permisos Manualmente

Si encuentras problemas de permisos al acceder a los logs desde WSL:

```bash
# Desde el contenedor
docker compose exec app php artisan logs:fix-permissions --recursive

# O manualmente desde el contenedor
docker compose exec app chown -R 1000:1000 storage/logs
docker compose exec app chmod -R 775 storage/logs
```

### Comando Artisan

Se incluye el comando `logs:fix-permissions` para ajustar permisos:

```bash
# Ajustar solo el directorio base
php artisan logs:fix-permissions

# Ajustar recursivamente todos los subdirectorios
php artisan logs:fix-permissions --recursive
```

### Permisos Automáticos

El `entrypoint-wrapper.sh` ajusta automáticamente los permisos de `storage/logs` al iniciar el contenedor, asegurando que los nuevos directorios sean accesibles desde WSL.

## Limpieza de Logs Antiguos

### Manual

```bash
# Eliminar logs de más de 30 días
find storage/logs -type d -mtime +30 -exec rm -rf {} \;
```

### Automático (Recomendado)

Crear un comando artisan o tarea programada:

```php
// En app/Console/Kernel.php o un comando dedicado
$schedule->command('logs:clean --days=30')->daily();
```

## Ejemplos de Uso

### Escribir en activity.log

```php
use Illuminate\Support\Facades\Log;

Log::channel('activity')->info('Usuario creado', ['user_id' => 123]);
```

### Escribir en security.log

```php
Log::channel('security')->warning('Intento de acceso denegado', [
    'ip' => request()->ip(),
    'user_id' => auth()->id(),
]);
```

### Usar LogService (recomendado)

```php
use App\Services\LogService;

// Activity log
LogService::logActivity('created', User::class, $user->id, [
    'before' => null,
    'after' => $user->toArray(),
]);

// Security log
LogService::logSecurity('permission_denied', 'Acceso denegado', [
    'permission' => 'users.delete',
    'user_id' => auth()->id(),
]);
```

## Notas Importantes

- Los directorios se crean automáticamente cuando se escribe el primer log del día
- Los permisos de los directorios son `0755` (lectura/escritura para propietario, lectura para otros)
- Los archivos se crean con permisos por defecto del sistema
- La estructura es compatible con sistemas de backup y rotación de logs

## Compatibilidad

- ✅ Funciona con Laravel Octane (cada worker crea sus propios logs)
- ✅ Compatible con sistemas de rotación de logs externos
- ✅ Funciona con Docker (volúmenes persistentes)
- ✅ Compatible con sistemas de backup automático

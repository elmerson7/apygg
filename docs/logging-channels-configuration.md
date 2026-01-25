# Configuración de Canales de Logging por Entorno

## Resumen

Los niveles de logging están configurados para adaptarse automáticamente según el entorno (dev/staging/prod), reduciendo ruido en desarrollo y enfocándose en errores importantes en producción.

---

## Niveles de Log por Entorno

### Desarrollo (dev)
- **Archivos de log**: `debug` (todo se registra)
- **Sentry**: `critical` (solo errores críticos)
- **Slack**: `critical` (solo errores críticos)

**Razón**: En desarrollo necesitas ver todo para debugging, pero no quieres spam en Sentry/Slack.

### Staging
- **Archivos de log**: `error` (solo errores y superior)
- **Sentry**: `error` (errores y críticos)
- **Slack**: `error` (errores y críticos)

**Razón**: Staging simula producción, solo errores importantes.

### Producción (prod)
- **Archivos de log**: `error` (solo errores y superior)
- **Sentry**: `error` (errores y críticos)
- **Slack**: `error` (errores y críticos)

**Razón**: En producción solo necesitas ver errores reales, no debug/info.

---

## Canales Configurados

### 1. `single` / `daily` (Archivos)
```php
'level' => env('LOG_LEVEL', env('APP_ENV') === 'prod' ? 'error' : 'debug')
```

**Comportamiento:**
- Dev: Registra todo (debug, info, warning, error, critical)
- Staging/Prod: Solo errores (error, critical, alert, emergency)

**Uso:**
```php
Log::info('Mensaje'); // Se registra en dev, NO en prod
Log::error('Error'); // Se registra siempre
```

---

### 2. `sentry` (Sentry)
```php
'level' => env('SENTRY_LOG_LEVEL', match(env('APP_ENV', 'dev')) {
    'dev' => 'critical',
    'staging', 'prod' => 'error',
    default => 'error',
})
```

**Comportamiento:**
- Dev: Solo `critical` (evita spam en desarrollo)
- Staging/Prod: `error` y superior (errores importantes)

**Uso:**
```php
Log::channel('sentry')->error('Error'); // Se envía en staging/prod, NO en dev
Log::channel('sentry')->critical('Crítico'); // Se envía siempre
```

---

### 3. `slack` (Slack)
```php
'level' => env('LOG_SLACK_LEVEL', env('APP_ENV') === 'prod' ? 'error' : 'critical')
```

**Comportamiento:**
- Dev: Solo `critical`
- Prod: `error` y superior

**Uso:**
```php
Log::channel('slack')->critical('Alerta crítica'); // Se envía siempre
Log::channel('slack')->error('Error'); // Se envía solo en prod
```

---

## Variables de Entorno

### Variables Disponibles

```env
# Nivel general de logging (afecta archivos)
LOG_LEVEL=debug          # dev: debug, staging/prod: error

# Nivel específico para Sentry (sobrescribe el match automático)
SENTRY_LOG_LEVEL=critical  # Opcional: forzar nivel específico

# Nivel específico para Slack
LOG_SLACK_LEVEL=critical   # Opcional: forzar nivel específico

# Canal por defecto
LOG_CHANNEL=stack          # stack, single, daily, etc.

# Canales en stack
LOG_STACK=single           # Canales separados por coma
```

### Valores Recomendados por Entorno

#### Desarrollo (.env)
```env
APP_ENV=dev
LOG_LEVEL=debug
# SENTRY_LOG_LEVEL no se necesita (usa 'critical' automáticamente)
```

#### Staging (env/staging.env)
```env
APP_ENV=staging
LOG_LEVEL=error
# SENTRY_LOG_LEVEL no se necesita (usa 'error' automáticamente)
```

#### Producción (env/prod.env)
```env
APP_ENV=prod
LOG_LEVEL=error
# SENTRY_LOG_LEVEL no se necesita (usa 'error' automáticamente)
```

---

## Integración con LogService

El `LogService` usa automáticamente el canal `sentry` con los niveles correctos:

```php
use App\Infrastructure\Services\LogService;

// En desarrollo: solo se envía a Sentry si es critical
LogService::error('Error'); // NO se envía a Sentry en dev
LogService::critical('Crítico'); // SÍ se envía a Sentry en dev

// En producción: se envía a Sentry si es error o critical
LogService::error('Error'); // SÍ se envía a Sentry en prod
LogService::critical('Crítico'); // SÍ se envía a Sentry en prod
```

---

## Tabla de Niveles por Entorno

| Nivel | Dev (Archivo) | Dev (Sentry) | Staging/Prod (Archivo) | Staging/Prod (Sentry) |
|-------|---------------|--------------|------------------------|----------------------|
| `debug` | ✅ Sí | ❌ No | ❌ No | ❌ No |
| `info` | ✅ Sí | ❌ No | ❌ No | ❌ No |
| `notice` | ✅ Sí | ❌ No | ❌ No | ❌ No |
| `warning` | ✅ Sí | ❌ No | ❌ No | ❌ No |
| `error` | ✅ Sí | ❌ No | ✅ Sí | ✅ Sí |
| `critical` | ✅ Sí | ✅ Sí | ✅ Sí | ✅ Sí |
| `alert` | ✅ Sí | ✅ Sí | ✅ Sí | ✅ Sí |
| `emergency` | ✅ Sí | ✅ Sí | ✅ Sí | ✅ Sí |

---

## Ejemplos de Uso

### Desarrollo
```php
// Se registra en archivo pero NO en Sentry
LogService::info('Usuario creado');
LogService::warning('Cache expirado');
LogService::error('Error de validación'); // Archivo sí, Sentry NO

// Se registra en archivo Y en Sentry
LogService::critical('Base de datos caída'); // Archivo sí, Sentry sí
```

### Producción
```php
// NO se registra en ningún lado
LogService::info('Usuario creado');
LogService::warning('Cache expirado');

// Se registra en archivo Y en Sentry
LogService::error('Error de validación'); // Archivo sí, Sentry sí
LogService::critical('Base de datos caída'); // Archivo sí, Sentry sí
```

---

## Override Manual

Si necesitas forzar un nivel específico independientemente del entorno:

```env
# Forzar Sentry a solo critical en producción
SENTRY_LOG_LEVEL=critical

# Forzar archivos a debug en producción (no recomendado)
LOG_LEVEL=debug
```

---

## Notas Importantes

1. **Los niveles se evalúan automáticamente** según `APP_ENV`, no necesitas configurar nada manualmente.

2. **Sentry en desarrollo**: Solo recibes `critical` para evitar spam mientras desarrollas.

3. **Archivos en desarrollo**: Registran todo para debugging completo.

4. **Producción**: Solo errores para reducir ruido y mejorar rendimiento.

5. **Los loggers especializados** (ActivityLogger, AuthLogger, etc.) guardan directamente en BD, no usan estos canales.

---

## Troubleshooting

### Sentry no recibe logs en desarrollo
✅ **Es normal**: En desarrollo solo se envían `critical`. Cambia a `error` si necesitas:
```env
SENTRY_LOG_LEVEL=error
```

### Demasiados logs en producción
✅ **Verifica**: `LOG_LEVEL=error` en producción. No debería ser `debug`.

### Sentry recibe demasiados errores
✅ **Ajusta**: `SENTRY_LOG_LEVEL=critical` para solo críticos.

---

## Referencias

- [Laravel Logging Documentation](https://laravel.com/docs/logging)
- [Monolog Levels](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Logger.php)
- [Sentry Laravel Integration](https://docs.sentry.io/platforms/php/guides/laravel/)

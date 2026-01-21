# Configuración de Sentry

## Descripción

Sentry es una plataforma de monitoreo de errores que captura excepciones, errores y problemas de rendimiento en tiempo real. Esta integración permite monitorear y rastrear errores en producción.

## Instalación

Sentry Laravel ya está instalado:
- `sentry/sentry-laravel` ^4.15

## Configuración

### Variables de Entorno

```env
# DSN de Sentry (obtener desde dashboard de Sentry)
SENTRY_LARAVEL_DSN=https://xxx@xxx.ingest.sentry.io/xxx

# O usar la variable alternativa
SENTRY_DSN=https://xxx@xxx.ingest.sentry.io/xxx

# Entorno (production, staging, development)
SENTRY_ENVIRONMENT=production

# Release version (opcional, para tracking de versiones)
SENTRY_RELEASE=v1.0.0

# Sample rate para traces (0.0 a 1.0)
# 0.0 = no traces, 1.0 = todos los traces
SENTRY_TRACES_SAMPLE_RATE=0.1

# Sample rate para profiles (0.0 a 1.0)
SENTRY_PROFILES_SAMPLE_RATE=0.0

# Enviar información personal identificable (PII)
SENTRY_SEND_DEFAULT_PII=false

# Nivel de log mínimo para enviar a Sentry
SENTRY_LOG_LEVEL=error

# Breadcrumbs
SENTRY_BREADCRUMBS_SQL_QUERIES=true
SENTRY_BREADCRUMBS_SQL_BINDINGS=false
SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS=true
SENTRY_BREADCRUMBS_QUEUE_INFO=true
SENTRY_BREADCRUMBS_COMMAND_INFO=true
```

### Obtener DSN de Sentry

1. Crear cuenta en [Sentry.io](https://sentry.io)
2. Crear un nuevo proyecto (Laravel)
3. Copiar el DSN desde la configuración del proyecto
4. Agregar al archivo `.env`

## Uso

### Captura Automática

Sentry captura automáticamente:
- Excepciones no manejadas
- Errores de PHP
- Errores de Laravel
- Errores de nivel `error` y superior en logs

### Captura Manual

```php
use Sentry\State\Scope;

// Capturar excepción
try {
    // código que puede fallar
} catch (\Exception $e) {
    \Sentry\captureException($e);
    throw $e;
}

// Capturar mensaje
\Sentry\captureMessage('Algo salió mal', \Sentry\Severity::error());

// Agregar contexto
\Sentry\configureScope(function (Scope $scope): void {
    $scope->setUser([
        'id' => auth()->id(),
        'email' => auth()->user()->email,
    ]);
    
    $scope->setTag('feature', 'payment');
    $scope->setContext('payment', [
        'amount' => 100,
        'currency' => 'USD',
    ]);
});
```

### Integración con Logging

Sentry está integrado con el sistema de logging de Laravel:

```php
// Enviar a Sentry automáticamente
Log::error('Error crítico', ['context' => 'data']);

// Usar canal específico
Log::channel('sentry')->error('Error solo para Sentry');
```

### Configurar Usuario

Sentry automáticamente captura información del usuario autenticado:

```php
// En un middleware o service provider
if (auth()->check()) {
    \Sentry\configureScope(function (Scope $scope): void {
        $scope->setUser([
            'id' => auth()->id(),
            'email' => auth()->user()->email,
            'username' => auth()->user()->name,
        ]);
    });
}
```

## Configuración Avanzada

### Filtrar Excepciones

En `config/sentry.php`:

```php
'ignored_exceptions' => [
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    // Agregar más excepciones a ignorar
],
```

### Callback Before Send

```php
'before_send' => [
    function (\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event {
        // Filtrar o modificar eventos antes de enviar
        if ($event->getMessage() === 'Error específico a ignorar') {
            return null; // No enviar
        }
        
        return $event;
    },
],
```

### Breadcrumbs

Los breadcrumbs capturan eventos que ocurren antes de un error:

- **SQL Queries**: Consultas de base de datos
- **HTTP Client Requests**: Requests HTTP salientes
- **Queue Info**: Información de colas
- **Command Info**: Información de comandos artisan

## Integración con Sistema de Logging

Sentry está configurado como canal de logging:

```php
// En config/logging.php
'stack' => [
    'driver' => 'stack',
    'channels' => ['single', 'sentry'], // Incluir sentry
],
```

O usar directamente:

```php
Log::channel('sentry')->error('Error crítico');
```

## Mejores Prácticas

1. **No enviar en desarrollo**: Configurar `SENTRY_LARAVEL_DSN` solo en producción
2. **Sample rate bajo**: Usar `SENTRY_TRACES_SAMPLE_RATE=0.1` para no saturar
3. **No enviar PII**: `SENTRY_SEND_DEFAULT_PII=false` por defecto
4. **Filtrar excepciones comunes**: Agregar a `ignored_exceptions`
5. **Usar releases**: Configurar `SENTRY_RELEASE` para tracking de versiones
6. **Contexto enriquecido**: Agregar contexto relevante antes de capturar

## Troubleshooting

### Sentry no captura errores

1. Verificar que `SENTRY_LARAVEL_DSN` esté configurado
2. Verificar que el DSN sea válido
3. Revisar logs de Laravel para errores de conexión
4. Verificar que el entorno esté configurado correctamente

### Demasiados eventos

1. Reducir `SENTRY_TRACES_SAMPLE_RATE`
2. Agregar más excepciones a `ignored_exceptions`
3. Usar `before_send` para filtrar eventos

### Información sensible

1. Verificar `SENTRY_SEND_DEFAULT_PII=false`
2. Usar `before_send` para sanitizar datos
3. Revisar breadcrumbs configurados

## Referencias

- [Sentry Laravel Documentation](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Sentry Dashboard](https://sentry.io)
- [Sentry PHP SDK](https://github.com/getsentry/sentry-php)

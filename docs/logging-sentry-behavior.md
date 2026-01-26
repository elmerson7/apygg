# Comportamiento de Sentry y Logs

## Resumen

Este documento explica cómo funciona Sentry en diferentes entornos, la diferencia entre los loggers especializados y `LogService`, y qué almacena cada sistema de logging.

## Sentry por Entorno

### Dev (Desarrollo)
- **Nivel mínimo**: `critical` únicamente
- **Razón**: Evitar spam de errores durante desarrollo
- **Configuración**: `config/logging.php` → canal `sentry` → `level: critical`
- **Qué se envía**: Solo errores críticos que requieren atención inmediata

### Staging
- **Nivel mínimo**: `error` y superior
- **Qué se envía**: 
  - `error`
  - `critical`
  - `alert`
  - `emergency`

### Prod (Producción)
- **Nivel mínimo**: `error` y superior
- **Qué se envía**: 
  - `error`
  - `critical`
  - `alert`
  - `emergency`

## Loggers Especializados vs LogService

### Loggers Especializados
- **Ubicación**: `app/Infrastructure/Logging/Loggers/`
- **Ejemplos**: `ActivityLogger`, `AuthLogger`, `SecurityLogger`, `ApiLogger`
- **Propósito**: Guardar logs estructurados en **tablas de base de datos**
- **Tablas**: `logs_activity`, `logs_security`, `logs_api`
- **Uso**: Se llaman directamente o mediante middleware/listeners
- **Ejemplo**:
```php
ActivityLogger::logCreated($user);
// Guarda en tabla activity_logs
```

### LogService
- **Ubicación**: `app/Infrastructure/Services/LogService.php`
- **Propósito**: Logging genérico con contexto enriquecido
- **Destinos**:
  1. **Archivo**: `storage/logs/laravel.log` (todos los niveles según configuración)
  2. **Sentry**: Solo `error` y `critical` (según entorno)
- **Uso**: Para logging general de la aplicación
- **Ejemplo**:
```php
LogService::error('Error en procesamiento', ['data' => $data]);
// Guarda en laravel.log Y envía a Sentry (si es error/critical)
```

## storage/logs/laravel.log vs Sentry

### laravel.log
- **Qué almacena**: Todos los logs según nivel configurado por entorno
  - Dev: `debug` y superior
  - Staging/Prod: `error` y superior
- **Formato**: Texto plano estructurado (JSON)
- **Ubicación**: Local en el servidor
- **Propósito**: 
  - Debugging local
  - Historial completo de logs
  - Análisis post-mortem
- **Retención**: Configurable (por defecto: 14 días con rotación diaria)

### Sentry
- **Qué almacena**: Solo errores críticos según entorno
  - Dev: Solo `critical`
  - Staging/Prod: `error` y superior
- **Formato**: Estructurado con contexto enriquecido
- **Ubicación**: Cloud (Sentry.io)
- **Propósito**:
  - Monitoreo en tiempo real
  - Alertas automáticas
  - Agrupación de errores similares
  - Tracking de releases
  - Análisis de tendencias
- **Retención**: Según plan de Sentry

## Resumen Visual

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUJO DE LOGGING                          │
└─────────────────────────────────────────────────────────────┘

┌─────────────────┐
│  Aplicación     │
└────────┬────────┘
         │
         ├──────────────────────────────────────┐
         │                                      │
         ▼                                      ▼
┌──────────────────┐              ┌──────────────────────┐
│ Loggers          │              │ LogService            │
│ Especializados   │              │ (Logging genérico)    │
└────────┬─────────┘              └───────────┬───────────┘
         │                                    │
         │                                    ├──────────────┐
         │                                    │              │
         ▼                                    ▼              ▼
┌──────────────────┐              ┌──────────────────┐  ┌──────────┐
│ Tablas BD        │              │ laravel.log      │  │ Sentry  │
│                  │              │                  │  │         │
│ • logs_activity │              │ • Todos los      │  │ • Solo  │
│ • logs_security │              │   niveles según  │  │   error │
│ • logs_api      │              │   entorno        │  │   y     │
│                  │              │                  │  │   critical│
└──────────────────┘              └──────────────────┘  └──────────┘
```

## Configuración Actual

### Niveles por Entorno

| Entorno | laravel.log | Sentry | Razón |
|---------|-------------|--------|-------|
| **dev** | `debug`+ | `critical` | Evitar spam en desarrollo |
| **staging** | `error`+ | `error`+ | Monitoreo completo |
| **prod** | `error`+ | `error`+ | Solo errores importantes |

### Archivos de Configuración

- **Logging**: `config/logging.php`
  - Canal `sentry`: Niveles por entorno
  - Canal `single`/`daily`: Niveles por entorno
  
- **LogService**: `app/Infrastructure/Services/LogService.php`
  - Envía automáticamente a Sentry cuando es `error` o `critical`
  - Respeta niveles configurados por entorno

## Notas Importantes

1. **Los loggers especializados NO envían a Sentry directamente**: Solo guardan en BD
2. **LogService SÍ envía a Sentry**: Automáticamente para `error` y `critical`
3. **Sentry respeta niveles por entorno**: Configurado en `config/logging.php`
4. **laravel.log siempre guarda**: Según nivel configurado por entorno
5. **Los loggers especializados son independientes**: No dependen de LogService

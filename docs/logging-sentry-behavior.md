# Comportamiento de Logging y Sentry

## Sentry por Entorno

### Desarrollo (dev)
- **Nivel mínimo**: `critical` (solo errores críticos)
- **Comportamiento**: Evita spam durante desarrollo
- **Ejemplo**: `LogService::error()` ❌ NO se envía | `LogService::critical()` ✅ SÍ se envía

### Staging
- **Nivel mínimo**: `error` (errores y críticos)
- **Comportamiento**: Simula producción, solo errores importantes

### Producción (prod)
- **Nivel mínimo**: `error` (errores y críticos)
- **Comportamiento**: Solo errores reales, no debug/info

---

## Loggers Especializados vs LogService

### Loggers Especializados (Independientes)
- `ActivityLogger`, `AuthLogger`, `SecurityLogger`, `ApiLogger`
- **Guardan**: Solo en tablas de BD (`activity_logs`, `security_logs`, `api_logs`)
- **NO envían**: Nada a Sentry

### LogService
- **Guarda**: Archivo (`laravel.log`) + Sentry (si cumple nivel)
- **Envío a Sentry**: Solo si es `error` o `critical` y cumple nivel del entorno

---

## storage/logs/laravel.log

### Qué almacena
Todos los logs de Laravel según nivel configurado:
- **Dev**: Todo (`debug`, `info`, `warning`, `error`, `critical`)
- **Prod**: Solo errores (`error`, `critical`)

### Formato
```
[2024-01-24 10:00:00] local.ERROR: Mensaje {"trace_id":"...","user_id":123}
```

---

## Comparación: laravel.log vs Sentry

| Aspecto | laravel.log | Sentry |
|---------|-------------|--------|
| Ubicación | Archivo local | Servicio en la nube |
| Formato | Texto plano | Dashboard web |
| Búsqueda | `grep`, `tail` | Búsqueda avanzada |
| Alertas | No | Sí (email, Slack) |
| Agrupación | No | Sí |
| Niveles (dev) | Todo (`debug`) | Solo `critical` |
| Niveles (prod) | Solo `error` | Solo `error` |

---

## Resumen Visual

```
┌─────────────────────────────────────────────────┐
│           Tu Aplicación                         │
└─────────────────────────────────────────────────┘
                    │
        ┌───────────┼───────────┐
        │           │           │
        ▼           ▼           ▼
┌─────────────┐ ┌──────────┐ ┌──────────────┐
│ LogService  │ │ Loggers  │ │ Excepciones │
│             │ │ Especial.│ │ No manejadas │
└─────────────┘ └──────────┘ └──────────────┘
        │           │           │
        │           ▼           │
        │    ┌──────────────┐   │
        │    │ Tablas BD    │   │
        │    │ (api_logs,   │   │
        │    │  error_logs) │   │
        │    └──────────────┘   │
        │                       │
        ▼                       ▼
┌─────────────┐         ┌─────────────┐
│ laravel.log │         │   Sentry    │
│ (archivo)   │         │  (nube)     │
│             │         │             │
│ Dev: debug  │         │ Dev: critical│
│ Prod: error │         │ Prod: error  │
└─────────────┘         └─────────────┘
```

---

## Ejemplos Prácticos

### Desarrollo
```php
LogService::info('Usuario creado');        // laravel.log: ✅ | Sentry: ❌
LogService::error('Error validación');     // laravel.log: ✅ | Sentry: ❌
LogService::critical('BD caída');          // laravel.log: ✅ | Sentry: ✅
```

### Producción
```php
LogService::info('Usuario creado');        // laravel.log: ❌ | Sentry: ❌
LogService::error('Error validación');     // laravel.log: ✅ | Sentry: ✅
LogService::critical('BD caída');          // laravel.log: ✅ | Sentry: ✅
```

### Loggers Especializados
```php
ActivityLogger::logCreated($user);         // Solo BD: ✅ | Sentry: ❌
AuthLogger::logLoginSuccess($user);        // Solo BD: ✅ | Sentry: ❌
ApiLogger::logRequest($request, $response); // Solo BD: ✅ | Sentry: ❌
```

---

## Notas Importantes

1. **Loggers especializados** guardan solo en BD, nunca envían a Sentry
2. **LogService** puede enviar a Sentry si cumple nivel del entorno
3. **laravel.log** es para debugging local, Sentry para monitoreo en producción
4. **Niveles automáticos** según `APP_ENV`, no necesitas configurar manualmente

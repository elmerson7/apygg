# Plan de Acción - API Boilerplate APYGG Laravel 12 (2026)

## Objetivo del Proyecto

Desarrollar un boilerplate de API robusto y production-ready basado en Laravel 12 que sirva como plantilla fundacional para futuros proyectos. Este boilerplate incluirá toda la infraestructura, patrones arquitectónicos y componentes comunes necesarios, permitiendo que al clonarlo se tenga disponible el 70% de la infraestructura base lista para usar.

El proyecto se denominará **APYGG** y será diseñado con arquitectura modular, escalable y mantenible desde el inicio.

---

## 📋 Resumen Ejecutivo

Este documento describe el plan de acción completo para construir el boilerplate API **APYGG** basado en Laravel 12. Este resumen extrae las decisiones arquitectónicas y componentes principales definidos en el plan detallado.

### Stack Tecnológico

- **Framework**: Laravel 12 + PHP 8.5
- **Servidor HTTP**: FrankenPHP (Octane) para alto rendimiento y concurrencia
- **Base de Datos**: PostgreSQL 18 (base principal `apygg` con particionamiento de logs)
- **Cache/Colas**: Redis 7 (cache, sesiones, colas) + Laravel Horizon
- **Búsqueda**: Meilisearch (opcional) via Laravel Scout
- **Observabilidad**: Laravel Telescope (desarrollo) + opciones para Prometheus/Grafana/OpenTelemetry

### Características Principales

- ✅ Arquitectura modular (`Core`, `Modules`, `Infrastructure`, `Helpers`)
- ✅ Clases base reutilizables (BaseController, BaseModel, BaseRequest, BaseResource)
- ✅ Autenticación JWT con refresh tokens
- ✅ Sistema RBAC (Roles y Permisos)
- ✅ Logging y auditoría completa (API, errores, seguridad, actividad)
- ✅ Colas asíncronas con Horizon
- ✅ Health checks para Kubernetes/Docker
- ✅ Rate limiting adaptativo
- ✅ Documentación automática de API (Scramble)

### Timeline Estimado

**Fases Iniciales** (Semanas 1-4): Setup, configuración de BD, infraestructura core  
**Features Principales** (Semanas 5-9): Autenticación, usuarios, logging, middleware  
**Observabilidad y Testing** (Semanas 10-15): Tests, optimizaciones, documentación  
**Despliegue** (Semanas 16-24): CI/CD, producción, monitoreo

> **Nota**: Ver secciones detalladas del plan para el calendario completo por fases.

---

## 🐳 Docker Compose - Resumen

### Servicios Principales

| Servicio | Descripción | Puerto |
|----------|-------------|--------|
| `app` | Contenedor PHP 8.5 + FrankenPHP (Octane). Aplicación Laravel | 8010 |
| `postgres` | PostgreSQL 18 - Base de datos principal `apygg` | 8011 |
| `redis` | Redis 7 - Cache, sesiones y driver de colas | 8014 |
| `meilisearch` | Motor de búsqueda full-text (opcional) | 8013 |
| `horizon` | Worker y dashboard de gestión de colas | - |
| `scheduler` | Ejecutor de tareas programadas (`schedule:work`) | - |

**Nota**: Ver sección 11 (Infraestructura Docker) para detalles completos de configuración.

### Comandos Básicos

```bash
# Levantar todos los servicios
docker compose --profile dev up -d

# Levantar servicios específicos
docker compose --profile dev up -d app postgres redis

# Ejecutar migraciones
docker compose exec app php artisan migrate

# Ver logs
docker compose logs -f app

# Acceder al shell del contenedor
docker compose exec app bash
```

---

## 🏗️ Arquitectura del Proyecto

### Flujo de Request

```
Cliente → FrankenPHP (Octane) / Laravel App
```

### Capas de la Aplicación

```
┌─────────────────────────────────────────┐
│         Presentation Layer              │
│    Routes / Controllers / API          │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│         Application Layer                │
│      Services / Use Cases               │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│         Domain / Core Layer              │
│      Models / Repositories               │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│      Infrastructure Layer                │
│  • PostgreSQL (apygg)                   │
│  • Redis (cache, colas)                 │
│  • Meilisearch (búsqueda, opcional)     │
│  • Horizon (gestión de colas)           │
│  • Scheduler (tareas programadas)       │
│  • Reverb (WebSockets, opcional)        │
└─────────────────────────────────────────┘
```

### Notas Importantes

- **Base de datos principal**: Todas las tablas de logs residen en la base de datos principal `apygg` y se gestionan mediante particionamiento mensual para optimizar consultas y limpieza.
- **Servidor HTTP**: **FrankenPHP (Octane)** actúa como servidor HTTP de alto rendimiento para mejorar latencia y concurrencia, con soporte nativo para TLS/SSL y manejo directo de requests.

---

## 1. Configuración Inicial del Proyecto

### 1.1 Instalación y Setup Base

**Creación del Proyecto:**
- Crear nuevo proyecto Laravel 12 desde cero usando `composer create-project`
- Establecer nombre del proyecto como `apygg` en `composer.json`
- Configurar estructura de directorios simple y práctica (estilo Laravel estándar):
  - `app/Http/Controllers/` - BaseController y controladores organizados por dominio (Auth, Users, Profiles, Logs, Health)
  - `app/Http/Requests/` - BaseRequest y requests organizados por dominio
  - `app/Http/Resources/` - BaseResource y resources organizados por dominio
  - `app/Http/Middleware/` - Middleware comunes (ForceJson, TraceId, RateLimitLogger, SecurityLogger, etc.)
  - `app/Models/` - BaseModel y modelos organizados (incluyendo Logs/)
  - `app/Services/` - Servicios reutilizables (HealthCheckService, Logging services)
  - `app/Traits/` - Traits reutilizables (HasUuid, LogsActivity, SoftDeletesWithUser)
  - `app/Logging/` - Clases de logging (JsonFormatter, DateLogger, Processors)
  - `app/Listeners/` - Event listeners organizados por dominio
  - `routes/api/` - Rutas organizadas por dominio (auth.php, users.php, profiles.php, logs.php, health.php)
- Establecer namespaces estándar de Laravel: `App\Http\Controllers`, `App\Models`, `App\Services`, etc.
- Configurar autoloading PSR-4 en `composer.json`

**Configuración de Entornos:**
- Crear archivo `.env.example` base con todas las variables necesarias documentadas
- Crear `dev.env.example` con debugging habilitado y valores de desarrollo
- Crear `staging.env.example` con valores cercanos a producción
- Crear `prod.env.example` con optimizaciones de producción y seguridad reforzada
- Documentar cada variable de entorno con comentarios explicando su propósito
- Establecer valores por defecto seguros para todas las variables

**Gestión de Dependencias:**
- Configurar `composer.json` con dependencias esenciales:
  - `php-open-source-saver/jwt-auth` para autenticación JWT
  - `laravel/octane` (FrankenPHP) para servidor HTTP de alto rendimiento
  - `laravel/horizon` para procesamiento de colas (opcional)
  - `laravel/telescope` para observabilidad en desarrollo
  - `laravel/scout` con driver Meilisearch (opcional)
  - `sentry/sentry-laravel` para logging de errores (opcional)
  - `spatie/laravel-query-builder` para query filters estandarizado
  - `dedoc/scramble` para documentación interactiva de API
- Establecer estructura de `config/` con archivos personalizados para cada servicio
- Configurar versiones compatibles de todos los paquetes

**Estructura de Directorios:**
- Crear directorios de tests: `tests/Unit/`, `tests/Feature/`
- Crear estructura Docker: `docker/`, `docker-compose.yml`
- Crear directorios de bases de datos: `database/migrations/`, `database/seeders/`
- Organizar rutas por dominio en `routes/api/`: `auth.php`, `users.php`, `profiles.php`, `logs.php`, `health.php`

**Convenciones de Naming (camelCase vs snake_case):**
- **snake_case** para: columnas de BD (`email_verified_at`, `last_login_at`), atributos de modelos, claves de arrays/config, nombres de tablas, variables de entorno
- **camelCase** para: métodos de clases (`sendSuccess()`, `loadRelations()`), variables en código PHP (`$userId`, `$requestData`), parámetros de funciones
- **PascalCase** para: nombres de clases (`BaseController`, `UserService`), namespaces (`App\Http\Controllers`, `App\Models`, `App\Services`), traits (`HasUuid`, `LogsActivity`)
- **JSON API**: usar snake_case en respuestas JSON para mantener consistencia con estándares REST
- Seguir convenciones estándar de Laravel y PSR-12 para máxima compatibilidad con el ecosistema

### 1.2 Configuración de Base de Datos

**Conexión Principal (apygg):**
- Configurar conexión a PostgreSQL principal en `config/database.php`
- Nombre de base de datos: `apygg`
- Configurar pool de conexiones básico con PDO (PDO::ATTR_PERSISTENT)
- Establecer timeout de conexión (30 segundos por defecto)
- Establecer migraciones en `database/migrations/` para esta conexión
- Crear base de datos `apygg` en PostgreSQL Docker

**PgBouncer (Connection Pooler) - Opcional pero Recomendado:**
- PgBouncer es un connection pooler para PostgreSQL que reduce el número de conexiones directas
- Útil para producción con alta carga y aplicaciones con muchas conexiones concurrentes
- Configuración en Docker Compose como servicio separado
- Modo `transaction` recomendado para Laravel (permite transacciones completas)
- Configuración de pool: `default_pool_size=25`, `max_client_conn=100`
- En desarrollo: conexión directa a PostgreSQL (sin PgBouncer)
- En producción: conexión a través de PgBouncer (puerto 6432)
- Variables de entorno: `DB_HOST=pgbouncer` para producción, `DB_HOST=postgres` para desarrollo
- Documentar cuándo usar PgBouncer vs conexión directa

**Particionamiento de Tablas de Logs:**
- Implementar particionamiento por fecha en tablas de logs (api_logs, error_logs, security_logs, activity_logs)
- Configurar particiones mensuales para optimizar consultas y limpieza
- Implementar políticas de retención y TTL por tipo de log
- Índices optimizados para consultas por fecha y usuario

**Nota sobre Escalabilidad:**
- Si en el futuro se requiere separar logs en otra base de datos, se puede migrar fácilmente
- La arquitectura actual permite escalar sin refactorización mayor
- Documentar proceso de migración a base de datos dual cuando sea necesario

### 1.3 Configuración de Entornos Docker

**Perfiles Docker Compose:**
- Crear perfiles para diferentes entornos: `dev`, `prod`
- Profile `dev`: Todos los servicios esenciales incluyendo Telescope para debugging
- Profile `prod`: Solo servicios esenciales, múltiples instancias para alta disponibilidad
- Staging puede usar profile `prod` con variables de entorno diferentes
- Configurar variables de entorno específicas por perfil
- Establecer valores por defecto seguros para desarrollo local
- **Nota:** Para gestión de bases de datos, usar herramientas de escritorio (TablePlus, DBeaver) o extensiones de VS Code

---

## 2. Infraestructura Core - Componentes Base

### 2.1 Clases Base del Sistema

**BaseController (`App\Http\Controllers\Controller`):**
- Implementar métodos comunes CRUD: `index()`, `show()`, `store()`, `update()`, `destroy()`
- Métodos de respuesta estándar: `sendSuccess()`, `sendError()`, `sendPaginated()`, `withMeta()`
- Manejo centralizado de respuestas API con formato consistente
- Manejo automático de excepciones comunes (404, 422, 500)
- Paginación estándar usando Laravel paginator
- Filtrado y ordenamiento base mediante query parameters
- Autorización base mediante traits reutilizables

**BaseRequest (`App\Http\Requests\BaseFormRequest`):**
- Extender `Illuminate\Foundation\Http\FormRequest`
- Implementar validaciones comunes reutilizables (UUIDs, emails, fechas)
- Métodos helper para validación de UUIDs: `validateUuid()`, `validateUuidArray()`
- Validación de fechas y formatos estándar ISO 8601
- Sanitización automática de inputs mediante middleware
- Método `authorize()` que verifica permisos usando policies
- Método `getValidationRules()` sobrescribible para flexibilidad
- Mensajes de error personalizados y consistentes en español

**BaseResource (`App\Http\Resources\BaseResource`):**
- Implementar formato RFC 7807 para respuestas de error
- Formato estándar para respuestas exitosas: `{success: true, data: {}, message: ""}`
- Métodos helper para transformación de datos: `transform()`, `transformCollection()`
- Manejo consistente de relaciones mediante `whenLoaded()`
- Inclusión condicional de metadatos y timestamps
- Soporte para relaciones opcionales mediante query parameters

**BaseModel (`App\Models\Model`):**
- Extender `Illuminate\Database\Eloquent\Model`
- Configuración común de timestamps (created_at, updated_at)
- Soft deletes configurado por defecto (deleted_at)
- UUIDs como primary keys usando trait `HasUuid`
- Traits comunes aplicados: `LogsActivity`, `SoftDeletesWithUser`, `Searchable`
- Scopes comunes: `active()`, `inactive()`, `recent()`, `oldest()`
- Todos los modelos (incluyendo logs) usan la conexión principal `apygg` por defecto

**BaseRepository (`App\Repositories\BaseRepository`) - Opcional:**
- Implementar patrón Repository como clase opcional para casos específicos
- Útil cuando se necesita abstracción de múltiples fuentes de datos o lógica compleja
- Para la mayoría de casos, usar Eloquent directamente en servicios es suficiente
- Métodos CRUD base: `all()`, `find()`, `findOrFail()`, `create()`, `update()`, `delete()`
- Query builders reutilizables para filtrado y ordenamiento
- Cache integrado en métodos comunes usando `CacheService`
- Documentar cuándo usar Repository vs Eloquent directo

### 2.2 Traits Reutilizables

**HasUuid (`App\Traits\HasUuid`):**
- Generación automática de UUID v4 en evento `creating` del modelo
- Configuración de primary key como UUID (no auto-incrementing)
- Validación de formato UUID en validaciones
- Método helper `isUuid()` para verificación

**LogsActivity (`App\Traits\LogsActivity`):**
- Registro automático de cambios en modelos mediante Observers
- Captura de valores antes/después del cambio en JSON
- Asociación automática con usuario autenticado que realiza el cambio
- Guardado en base de datos principal (`apygg.activity_logs`) con particionamiento
- Filtrado de campos sensibles (passwords, tokens) antes de guardar
- Configuración de modelos a auditar mediante propiedad `$auditable`

**SoftDeletesWithUser (`App\Traits\SoftDeletesWithUser`):**
- Extiende soft deletes nativo de Laravel
- Registro de usuario que eliminó el registro en campo `deleted_by`
- Timestamp de eliminación con información de usuario
- Restauración con auditoría del usuario que restaura
- Métodos helper: `restore()`, `forceDelete()`

**Searchable (`App\Traits\Searchable`):**
- Integración con Meilisearch mediante Laravel Scout
- Indexación automática en eventos `created`, `updated`, `deleted`
- Búsqueda full-text configurada con filtros y facetas
- Sincronización de índices mediante comandos artisan
- Método `toSearchableArray()` para definir campos indexables
- Configuración de filtros y ranking personalizado

**HasApiTokens (`App\Traits\HasApiTokens`):**
- Soporte para API keys personales de usuarios
- Métodos para crear, revocar, listar tokens: `createToken()`, `revokeToken()`, `tokens()`
- Scope para filtrar por token activo: `whereToken()`
- Validación de expiración automática
- Hash seguro de tokens antes de almacenar

### 2.3 Servicios Base

**CacheService (`App\Services\CacheService`):**
- Abstracción sobre Redis/Cache de Laravel
- Métodos principales: `get()`, `set()`, `forget()`, `remember()`
- Tags para invalidación selectiva: `tag()`, `forgetTag()`
- Configuración de TTL por tipo de dato (configurable)
- Métodos especializados: `rememberUser()`, `rememberEntity()`, `rememberSearch()`
- Método `getAllMetrics()` para monitoreo de hit rate y uso de memoria
- Invalidación inteligente basada en eventos de modelos

**LogService (`App\Services\Logging\ActivityLogger`, `App\Services\Logging\AuthLogger`, `App\Services\Logging\SecurityLogger`):**
- Logging centralizado con niveles: debug, info, warning, error, critical
- Método genérico `log()` con contexto enriquecido
- Métodos específicos: `logApi()`, `logActivity()`, `logSecurity()`, `logError()`
- Captura automática de: trace_id, user_id, IP, user_agent, request data
- Almacenamiento en base de datos principal con tablas particionadas según tipo
- Integración con Sentry para errores críticos (severity >= error)
- Limpieza automática de logs antiguos según TTL configurado usando particiones

**NotificationService (`App\Services\NotificationService`):**
- Servicio centralizado de notificaciones multi-canal
- Métodos para email, SMS, push notifications, database
- Implementación de colas para notificaciones asíncronas
- Historial de notificaciones enviadas en base de datos
- Templates reutilizables con sistema de variables
- Configuración de canales por tipo de notificación
- Retry automático en caso de fallo

**SecurityService (`App\Services\SecurityService`):**
- Encriptación/desencriptación de datos sensibles usando Laravel Crypt
- Hashing de contraseñas usando bcrypt con configuración de rounds
- Validación de IP contra whitelist configurable
- Detección de comportamiento sospechoso mediante análisis de patrones
- Rate limiting adaptativo basado en historial de requests
- Generación de tokens seguros para recuperación de contraseña

### 2.4 Helpers y Utilidades

**ApiResponse (`App\Helpers\ApiResponse`):**
- Clase estática con métodos para respuestas estándar
- Métodos principales:
  - `success($data, $message, $statusCode = 200)` - Respuesta exitosa
  - `error($message, $statusCode = 400, $errors = [])` - Respuesta de error
  - `validation($errors)` - Errores de validación (422)
  - `notFound($message = 'Recurso no encontrado')` - 404
  - `unauthorized($message = 'No autenticado')` - 401
  - `forbidden($message = 'No autorizado')` - 403
  - `rateLimited($message = 'Límite de requests excedido')` - 429
  - `serverError($message = 'Error interno del servidor')` - 500
  - `paginated($data, $pagination)` - Respuesta paginada
  - `created($data, $message = 'Creado exitosamente')` - 201
- Formato estándar mejorado con metadatos:
  ```json
  {
    "success": true,
    "data": {...},
    "meta": {
      "version": "1.0",
      "timestamp": "2025-01-01T00:00:00Z",
      "request_id": "uuid",
      "execution_time_ms": 45
    },
    "links": {
      "self": "/api/v1/users/123"
    }
  }
  ```
- Formato RFC 7807 para errores con detalles estructurados
- Headers estándar incluidos (Content-Type, X-Trace-ID)

**DateHelper (`App\Helpers\DateHelper`):**
- Métodos para formateo de fechas según región/configuración
- Conversión de timezones usando Carbon
- Cálculo de diferencias de tiempo en formato legible
- Parsing de fechas en múltiples formatos (ISO 8601, español, etc.)
- Métodos para rangos de fechas: `getDateRange()`, `isWithinRange()`
- Validación de formatos de fecha

**SecurityHelper (`App\Helpers\SecurityHelper`):**
- Generación de tokens seguros usando `random_bytes()` y `bin2hex()`
- Validación de contraseñas fuertes (mínimo 8 caracteres, mayúscula, número, símbolo)
- Sanitización de input HTML usando `strip_tags()` y `htmlspecialchars()`
- Validación de URLs contra whitelist
- Métodos anti-CSRF para formularios
- Enmascaramiento de datos sensibles para logging

**StringHelper (`App\Helpers\StringHelper`):**
- Generación de slugs: `slugify()`
- Truncamiento de strings: `truncate()`, `truncateWords()`
- Conversión de casos: `toCamelCase()`, `toSnakeCase()`, `toPascalCase()`
- Pluralización/singularización: `pluralize()`, `singularize()`
- Enmascaramiento de strings para datos sensibles: `mask()`
- Validación de formatos específicos

---

## 3. Sistema de Autenticación y Autorización

### 3.0 Versionado de API

**Configuración de Rutas:**
- Todas las rutas API bajo prefijo `/api/v1/`
- Estructura preparada para versionado futuro (v2, v3, etc.)
- Middleware `ApiVersionMiddleware` para manejo de versiones
- Headers de versión en request/response (`X-API-Version`)
- Documentación de estrategia de versionado y compatibilidad hacia atrás

### 3.1 Autenticación JWT

**Instalación y Configuración:**
- Instalar y configurar `php-open-source-saver/jwt-auth` para autenticación JWT
- Configurar secretos en `.env` (`JWT_SECRET`)
- Configurar tiempos de expiración: access token (15 minutos), refresh token (7 días)
- Implementar refresh tokens con rotación automática
- Configurar blacklist de tokens revocados en tabla `jwt_blacklist`
- Configurar claims estándar: iss (issuer), aud (audience), exp (expiration), iat (issued at), sub (subject)

**AuthController (`App\Http\Controllers\Auth\AuthController`):**
- Endpoint `POST /api/v1/auth/login` - Login con email/contraseña, retorna JWT y refresh token
- Endpoint `POST /api/v1/auth/register` - Registro de nuevos usuarios (si está habilitado)
- Endpoint `POST /api/v1/auth/logout` - Cerrar sesión y revocar token agregándolo a blacklist
- Endpoint `POST /api/v1/auth/refresh` - Renovar access token usando refresh token
- Endpoint `GET /api/v1/auth/me` - Obtener datos del usuario autenticado
- Validación de credenciales contra base de datos
- Rate limiting estricto en endpoints de autenticación (5 intentos por minuto)
- Registro de intentos de login (exitosos y fallidos) en SecurityLog
- Generación de JWT con claims: user_id, roles, permissions, exp

**TokenService (`App\Services\Auth\TokenService`):**
- Generación de access tokens con expiración corta
- Generación de refresh tokens con expiración larga
- Validación de tokens (integridad, expiración, blacklist)
- Revocación de tokens agregándolos a blacklist
- Renovación automática con rotación de refresh tokens
- Extracción de claims del token para autorización

**AuthService (`App\Services\Auth\AuthService`):**
- Lógica de negocio de autenticación separada del controlador
- Método `authenticate($credentials)` - Valida credenciales y retorna usuario
- Método `generateTokens($user)` - Genera JWT y refresh token
- Método `refreshToken($token)` - Genera nuevo access token desde refresh token
- Método `revokeToken($token)` - Invalida token
- Manejo de intentos fallidos con bloqueo temporal después de 5 intentos
- Registro de eventos de autenticación en SecurityLog

### 3.2 Recuperación de Contraseña

**PasswordController (`App\Http\Controllers\Auth\PasswordController`):**
- Endpoint `POST /api/v1/auth/forgot-password` - Solicitar reset, envía email con token
- Endpoint `POST /api/v1/auth/reset-password` - Resetear contraseña con token válido
- Endpoint `POST /api/v1/auth/change-password` - Cambiar contraseña si está autenticado
- Validación de tokens de reset (existencia, expiración)
- Expiración de tokens de reset (1 hora)

**Lógica de Negocio:**
- Generación de tokens seguros usando `SecurityHelper::generateToken()`
- Envío de emails con enlaces de reset usando `NotificationService`
- Validación de contraseñas nuevas según política de complejidad
- Historial de cambios de contraseña en SecurityLog
- Invalidación de tokens después de uso exitoso

### 3.3 Sistema RBAC (Role-Based Access Control)

**Modelos:**
- Modelo `Role` (`App\Models\Role`) con campos: name (único), display_name, description
- Modelo `Permission` (`App\Models\Permission`) con campos: name (único), display_name, resource, action, description
- Tabla pivot `role_permission` para asignación muchos-a-muchos
- Tabla pivot `user_role` para asignación de roles a usuarios
- Tabla `user_permission` para permisos directos que sobrescriben roles

**Funcionalidades:**
- Asignación de roles a usuarios mediante `UserService::assignRole()`
- Asignación de permisos a roles mediante `RoleService::assignPermission()`
- Verificación de permisos en middleware `CheckPermission`
- Verificación de roles en policies de Laravel
- Cache de permisos para performance usando `CacheService`
- Cálculo de permisos efectivos (roles + permisos directos)

**Seeders:**
- Roles base: Admin (acceso total), User (acceso básico), Guest (solo lectura)
- Permisos base del sistema con estructura `resource.action` (users.create, users.read, etc.)
- Asignación inicial de permisos a roles según jerarquía

**Policies:**
- Policies base para recursos comunes (`UserPolicy`, `RolePolicy`)
- Verificación de permisos en métodos de policies
- Integración con sistema de roles mediante helpers
- Autorización granular por acción (view, create, update, delete)

---

## 4. Feature Flags

### 4.1 Sistema de Feature Flags Simplificado

**Configuración Inicial (Archivo de Configuración):**
- Archivo `config/features.php` con array de features y su estado
- Estructura simple: `'feature-name' => ['enabled' => true, 'description' => '...']`
- Fácil de versionar y revisar en Git
- Sin dependencias de base de datos para el caso básico
- Cache de configuración para performance

**Clase Helper Feature:**
- Clase `App\Helpers\Feature` con método estático `Feature::enabled('feature-name')`
- Lee desde `config('features.feature-name.enabled')` por defecto
- Misma API que si fuera desde base de datos, facilitando migración futura
- Cache automático de configuración usando `CacheService`

**Ejemplo de Configuración (`config/features.php`):**
```php
return [
    'new-dashboard' => [
        'enabled' => false,
        'description' => 'Nuevo dashboard de usuario',
    ],
    'advanced-search' => [
        'enabled' => true,
        'description' => 'Búsqueda avanzada con filtros',
    ],
    'email-notifications' => [
        'enabled' => true,
        'description' => 'Sistema de notificaciones por email',
    ],
];
```

**Uso en Código:**
```php
// Verificar si un feature está habilitado
if (Feature::enabled('new-dashboard')) {
    // Lógica del nuevo dashboard
}

// Con valor por defecto si no existe
if (Feature::enabled('experimental-feature', false)) {
    // Lógica experimental
}
```

**Migración Futura a Base de Datos:**
- La migración `create_features_table` está documentada pero NO se ejecuta por defecto
- Cuando se necesite toggle dinámico sin deploy, migrar a tabla `features`
- El helper `Feature::enabled()` puede leer de base de datos manteniendo la misma API
- Documentación completa del proceso de migración disponible en `docs/feature-flags-migration.md`

**Ventajas del Enfoque Simplificado:**
- Menos complejidad inicial: no requiere migración ni modelo
- Versionado claro: cambios de features visibles en Git
- Misma API: fácil migrar a base de datos cuando sea necesario
- Adecuado para el 80% de casos de uso iniciales

---

## 5. Módulo de Usuarios

### 4.1 Gestión de Usuarios

**UserController (`App\Http\Controllers\Users\UserController`):**
- `GET /api/v1/users` - Listar usuarios con paginación, filtrado y ordenamiento usando Query Filters
- `GET /api/v1/users/{id}` - Obtener usuario específico con relaciones opcionales
- `POST /api/v1/users` - Crear nuevo usuario (solo admin)
- `PUT /api/v1/users/{id}` - Actualizar usuario (admin o el usuario mismo)
- `DELETE /api/v1/users/{id}` - Eliminar usuario con soft delete (solo admin)
- `POST /api/v1/users/{id}/restore` - Restaurar usuario eliminado
- `POST /api/v1/users/{id}/roles` - Asignar roles a usuario
- `DELETE /api/v1/users/{id}/roles/{roleId}` - Remover rol de usuario
- `GET /api/v1/users/{id}/activity` - Historial de actividad del usuario

**Query Filters Estandarizado:**
- Uso de `spatie/laravel-query-builder` para filtros consistentes
- Filtros por campos: `?filter[status]=active&filter[role_id]=1`
- Filtros por rango: `?filter[created_at][gte]=2025-01-01&filter[created_at][lte]=2025-12-31`
- Ordenamiento: `?sort=name,-created_at` (ascendente, descendente)
- Inclusión de relaciones: `?include=roles,permissions`
- Paginación estándar: `?page=1&per_page=20`

**UserService (`App\Modules\Users\Services\UserService`):**
- Lógica de creación de usuarios con validaciones
- Validación de emails únicos antes de crear
- Hash de contraseñas usando bcrypt
- Asignación de roles por defecto (User) si no se especifica
- Notificaciones de bienvenida mediante `NotificationService`
- Métodos CRUD completos: `create()`, `update()`, `delete()`, `restore()`
- Métodos de gestión de roles: `assignRole()`, `removeRole()`
- Métodos de gestión de permisos: `assignPermission()`, `removePermission()`
- Búsqueda de usuarios con filtros avanzados

**User Model (`App\Modules\Users\Models\User`):**
- Campos: id (UUID), name, email (único), password (hashed), phone, avatar, is_active, email_verified_at, last_login_at
- Relaciones: `roles()`, `permissions()`, `apiTokens()`, `activityLogs()`
- Scopes: `active()`, `inactive()`, `byEmail()`, `byRole()`
- Métodos helper: `isAdmin()`, `hasPermission()`, `hasAnyPermission()`, `hasAllPermissions()`
- Traits: `HasUuid`, `LogsActivity`, `SoftDeletesWithUser`, `HasApiTokens`, `Searchable`

**Form Requests:**
- `StoreUserRequest` - Validación de creación: email único, password fuerte, nombre requerido
- `UpdateUserRequest` - Validación de actualización: email único excepto si es el mismo usuario
- `AssignRoleRequest` - Validación de asignación de roles: role_id debe existir

**Resources:**
- `UserResource` - Transformación básica de datos de usuario (sin información sensible)
- `UserDetailResource` - Transformación completa con permisos efectivos y tokens
- Inclusión condicional de relaciones mediante query parameters
- Ocultación de datos sensibles según contexto (propio usuario vs admin)

---

## 6. Sistema de Logging y Auditoría

### 5.1 Infraestructura de Logging

**Modelos de Logs (en base de datos principal `apygg` con particionamiento):**

**ApiLog (`App\Infrastructure\Logging\Models\ApiLog`):**
- Campos: id, trace_id (UUID único por request), user_id, request_method, request_path, request_query (JSON), request_body (JSON sanitizado), request_headers (JSON), response_status, response_body (JSON opcional), response_time_ms, user_agent, ip_address, created_at
- Registra TODOS los requests/responses de la API
- TTL: 90 días (política de retención configurable)
- Índices: trace_id, user_id, created_at (para purgas eficientes)
- Particionamiento por mes para optimizar consultas y limpieza

**ErrorLog (`App\Infrastructure\Logging\Models\ErrorLog`):**
- Campos: id, trace_id, user_id, exception_class, message, file, line, stack_trace (text), context (JSON), severity (enum: low, medium, high, critical), resolved_at, created_at
- Captura todas las excepciones no manejadas
- TTL: 180 días
- Índices: trace_id, user_id, severity, created_at
- Particionamiento por mes
- Integración con Sentry para errores críticos

**SecurityLog (`App\Infrastructure\Logging\Models\SecurityLog`):**
- Campos: id, trace_id, user_id, event_type (enum: login_success, login_failure, permission_denied, suspicious_activity, password_changed), ip_address, user_agent, details (JSON), created_at
- Eventos de seguridad: intentos fallidos, cambios de permisos, accesos denegados
- TTL: 1 año
- Índices: event_type, user_id, created_at
- Particionamiento por mes
- Alertas automáticas para eventos críticos

**ActivityLog (`App\Infrastructure\Logging\Models\ActivityLog`):**
- Campos: id, user_id, model_type, model_id, action (enum: created, updated, deleted, restored), old_values (JSON), new_values (JSON), ip_address, created_at
- Auditoría completa de cambios en modelos
- TTL: 2 años
- Índices: user_id, model_type, model_id, created_at
- Particionamiento por mes
- Comparación de valores antes/después para auditoría detallada

### 5.2 Loggers Especializados

**ActivityLogger (`App\Infrastructure\Logging\Loggers\ActivityLogger`):**
- Registro automático mediante Observers de Laravel
- Captura de cambios en modelos específicos configurados
- Comparación de valores antes/después usando `array_diff()`
- Filtrado de campos sensibles antes de guardar
- Asociación automática con usuario autenticado

**AuthLogger (`App\Infrastructure\Logging\Loggers\AuthLogger`):**
- Registro de intentos de login (exitosos y fallidos)
- Registro de cambios de contraseña
- Registro de renovación de tokens
- Detección de patrones sospechosos (múltiples fallos en corto tiempo)
- Alertas automáticas después de 5 intentos fallidos

**SecurityLogger (`App\Infrastructure\Logging\Loggers\SecurityLogger`):**
- Middleware para registro de eventos de seguridad
- Detección de intentos de acceso no autorizado
- Registro de cambios en permisos y roles
- Alertas automáticas para eventos críticos mediante `NotificationService`

**ApiLogger (`App\Infrastructure\Logging\Loggers\ApiLogger`):**
- Middleware para registro de todas las peticiones HTTP
- Captura de request/response con sanitización de datos sensibles
- Cálculo de tiempo de ejecución usando `microtime()`
- Generación de trace IDs únicos por request
- Filtrado de endpoints de health check para reducir ruido

### 5.3 Configuración de Canales de Logging

**Configuración en `config/logging.php`:**
- Canal `database_logs` para almacenamiento en base de datos principal (`apygg`) con tablas particionadas
- Canal `file` para backup en archivos (solo errores críticos)
- Canal `sentry` para errores críticos en Sentry
- Niveles de log por canal configurados según entorno
- Formato de logs consistente con trace_id y contexto enriquecido

---

## 7. Middleware y Seguridad

### 6.1 Middleware Personalizados

**TraceIdMiddleware (`App\Http\Middleware\TraceIdMiddleware`):**
- Generación de UUID único por request usando `Str::uuid()`
- Inyección en headers de respuesta (`X-Trace-ID`)
- Disponible en contexto de logging mediante `Log::withContext()`
- Rastreo completo del request a través de toda la aplicación
- Persistencia en cache para correlación con logs asíncronos

**SecurityLoggerMiddleware (`App\Http\Middleware\SecurityLoggerMiddleware`):**
- Registro de eventos de seguridad en SecurityLog
- Detección de patrones anómalos (múltiples 403, 401 en corto tiempo)
- Integración con sistema de alertas para notificaciones
- Rate limiting adaptativo basado en historial

**RateLimitLoggerMiddleware (`App\Http\Middleware\RateLimitLoggerMiddleware`):**
- Registro de intentos de rate limiting bloqueados
- Métricas de uso por usuario/IP para análisis
- Alertas de abuso detectado mediante `NotificationService`
- Logging en SecurityLog para auditoría

**CorsMiddleware (`App\Http\Middleware\CorsMiddleware`):**
- Configuración de CORS por entorno (desarrollo vs producción)
- Whitelist de dominios permitidos desde configuración
- Headers permitidos: Content-Type, Authorization, X-Requested-With, X-Trace-ID
- Métodos HTTP permitidos: GET, POST, PUT, DELETE, PATCH, OPTIONS
- Credenciales habilitadas para cookies y autenticación

**ApiVersionMiddleware (`App\Http\Middleware\ApiVersionMiddleware`):**
- Preparado para futuro versionado de API
- Headers de versión en request/response
- Routing condicional por versión (v1, v2, etc.)
- Compatibilidad hacia atrás configurable

### 6.2 Rate Limiting Adaptativo

**Configuración:**
- Límites diferentes por endpoint configurados en `RouteServiceProvider`
- Límites por usuario autenticado vs anónimo (más permisivo para autenticados)
- Límites por IP para prevenir abuso
- Ventanas de tiempo configurables (por minuto, hora, día)
- Configuración en `config/rate-limiting.php`

**Implementación:**
- Uso de Redis para contadores distribuidos
- Algoritmo de sliding window para precisión
- Respuestas con headers informativos: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- Logging de intentos bloqueados en SecurityLog
- Respuesta estándar 429 con mensaje descriptivo

**Estrategias:**
- Endpoints de autenticación: 5 requests por minuto por IP
- Endpoints de lectura: 60 requests por minuto por usuario
- Endpoints de escritura: 30 requests por minuto por usuario
- Endpoints administrativos: 10 requests por minuto por usuario autenticado

### 6.3 Otras Medidas de Seguridad

**Validación de Inputs:**
- Sanitización automática mediante middleware `SanitizeInput`
- Validación estricta de tipos en Form Requests
- Protección contra SQL injection mediante Eloquent (prepared statements)
- Protección contra XSS mediante sanitización de HTML y escape de output

**Encriptación:**
- Datos sensibles encriptados en base de datos usando `encrypt()`
- Claves de encriptación rotables mediante configuración
- Encriptación de comunicaciones mediante HTTPS (TLS 1.3)
- Almacenamiento seguro de secrets en variables de entorno

**IP Whitelisting:**
- Sistema de whitelist para endpoints críticos (admin)
- Configuración por entorno en `config/security.php`
- Logging de intentos desde IPs no permitidas
- Middleware `IpWhitelist` para verificación

**Security Headers:**
- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY
- X-XSS-Protection: 1; mode=block
- Strict-Transport-Security: max-age=31536000 (solo HTTPS)
- Content-Security-Policy: default-src 'self'
- Referrer-Policy: strict-origin-when-cross-origin

---

## 8. Health Checks y Monitoreo

### 7.1 Health Check Endpoints

**HealthController (`App\Modules\System\Controllers\HealthController`):**
- `GET /api/health` - Health check básico sin autenticación (para load balancers)
  - Respuesta simple: `{ "status": "ok", "version": "1.0", "timestamp": "2025-01-01T00:00:00Z" }`
  - Verificación rápida de conectividad a base de datos principal
  - Sin dependencias externas para respuesta rápida
  - Redirige a `/api/health/ready` para compatibilidad

- `GET /api/health/live` - Liveness probe (Kubernetes/Docker Swarm)
  - **Propósito:** Verificar que la aplicación está viva y respondiendo
  - **Sin verificación de servicios externos** (DB, Redis, etc.)
  - Respuesta rápida: solo verifica que PHP/Laravel responde
  - Respuesta: `{ "status": "alive", "timestamp": "2025-01-01T00:00:00Z" }`
  - **Uso:** Kubernetes usa esto para saber si debe reiniciar el contenedor
  - Timeout recomendado: 5 segundos
  - Si falla: Kubernetes reinicia el pod

- `GET /api/health/ready` - Readiness probe (Kubernetes/Docker Swarm)
  - **Propósito:** Verificar que la aplicación está lista para recibir tráfico
  - **Verifica servicios críticos:** Base de datos principal (`apygg`), Redis
  - **No verifica servicios opcionales:** Meilisearch, Horizon (solo si están configurados)
  - Respuesta detallada con estado de cada servicio:
    ```json
    {
      "status": "ready",
      "version": "1.0",
      "timestamp": "2025-01-01T00:00:00Z",
      "services": {
        "database": { "status": "ok", "latency_ms": 12 },
        "redis": { "status": "ok", "latency_ms": 2 }
      }
    }
    ```
  - **Uso:** Kubernetes usa esto para saber si puede enviar tráfico al pod
  - Timeout recomendado: 10 segundos
  - Si falla: Kubernetes deja de enviar tráfico pero NO reinicia el pod

- `GET /api/health/detailed` - Health check detallado con autenticación requerida
  - Verificación completa de todos los servicios (incluyendo opcionales)
  - Verificación de conectividad a base de datos principal (`apygg`)
  - Verificación de conectividad a Redis (ping y latencia)
  - Verificación de conectividad a Meilisearch (health endpoint) - opcional
  - Estado de colas (Horizon): workers activos, jobs pendientes - opcional
  - Versión de la aplicación desde `config/app.php`
  - Timestamp del check en ISO 8601
  - Requiere autenticación JWT (solo para administradores)

**Respuesta de Health Check Detallado:**
- Status general: `healthy` (todo OK), `degraded` (servicios opcionales con problemas), `unhealthy` (servicios críticos caídos)
- Estado individual de cada servicio con latencia en milisegundos
- Solo verifica servicios críticos en el básico, todos en el detallado

**Diferencia entre Liveness y Readiness:**
- **Liveness (`/live`):** "¿Está la app funcionando?" → Si NO: reiniciar contenedor
- **Readiness (`/ready`):** "¿Puede la app manejar tráfico?" → Si NO: dejar de enviar tráfico
- **Ejemplo:** Si la BD está caída, `/live` responde OK (app funciona), pero `/ready` falla (no puede manejar requests)

**Configuración Recomendada para Kubernetes:**
```yaml
livenessProbe:
  httpGet:
    path: /api/health/live
    port: 8010
  initialDelaySeconds: 30
  periodSeconds: 10
  timeoutSeconds: 5
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /api/health/ready
    port: 8010
  initialDelaySeconds: 10
  periodSeconds: 5
  timeoutSeconds: 10
  failureThreshold: 3
```

### 7.2 Sistema de Observabilidad con Laravel Telescope

**Laravel Telescope:**
- Instalación y configuración de Laravel Telescope para desarrollo y debugging
- Dashboard accesible en `/telescope` (solo en entorno de desarrollo)
- Monitoreo de requests, queries, jobs, eventos, logs, excepciones
- Filtrado y búsqueda avanzada de eventos
- Integración con sistema de logging existente

**Configuración:**
- Habilitado solo en entorno `dev` y `staging`
- Deshabilitado en producción por seguridad y performance
- Configuración de filtros para datos sensibles
- Retención configurable de datos históricos

**Nota sobre Observabilidad Avanzada:**
- Para proyectos que migren a microservicios, documentar cómo agregar Prometheus + Grafana
- Para tracing distribuido, documentar cómo integrar OpenTelemetry cuando sea necesario
- El objetivo es mantener el boilerplate simple pero extensible

### 7.3 Integración con Sentry

**Configuración:**
- Instalación y configuración de SDK `sentry/sentry-laravel` (opcional)
- Captura automática de excepciones no manejadas
- Contexto enriquecido: usuario autenticado, request data, entorno
- Niveles de severidad configurados (solo error y critical en producción)
- Integración con `LogService` para envío automático

**Alertas:**
- Configuración de alertas por tipo de error en Sentry
- Notificaciones para errores críticos mediante webhooks
- Agrupación inteligente de errores similares
- Dashboard de errores en tiempo real

**Nota:** Sentry es opcional y puede configurarse cuando se necesite monitoreo de errores en producción

---

## 9. Procesamiento Asíncrono

### 8.1 Configuración de Colas

**Configuración de Redis como Driver:**
- Configuración en `config/queue.php` con Redis como driver principal
- Múltiples colas con prioridades: `high`, `default`, `low`
- Timeout configurado: 60 segundos por job
- Retry configurado: 3 intentos con backoff exponencial
- Dead letter queue para jobs fallidos después de todos los reintentos
- Configuración de conexión Redis desde `.env`

**Jobs Base:**
- Clase base `App\Core\Jobs\BaseJob` con logging integrado
- Manejo de excepciones estándar con registro en ErrorLog
- Retry automático configurado con backoff exponencial
- Notificaciones de fallos mediante `NotificationService`
- Métodos helper: `log()`, `handleException()`

### 8.2 Laravel Horizon

**Instalación y Configuración:**
- Instalación de Horizon mediante `composer require laravel/horizon`
- Configuración de workers en `config/horizon.php`
- Configuración de balanceadores por entorno
- Configuración de auto-scaling basado en carga
- Dashboard accesible en `/horizon` (protegido en producción)

**Dashboard:**
- Acceso al dashboard de Horizon para monitoreo en tiempo real
- Visualización de jobs en cola, procesando, completados, fallidos
- Métricas de throughput (jobs/segundo)
- Gestión de jobs fallidos con opción de retry manual
- Filtros por cola, estado, tipo de job

**Configuración de Colas:**
- Cola `high`: notificaciones críticas, procesamiento inmediato (3 workers)
- Cola `default`: procesamiento general (2 workers)
- Cola `low`: tareas batch, procesamiento bajo prioridad (1 worker)
- Cola `emails`: envío de emails (2 workers)
- Cola `logs`: procesamiento de logs (1 worker)

### 8.3 Scheduler de Tareas

**Tareas Programadas (`app/Console/Kernel.php`):**
- Limpieza de JWT blacklist expirados: cada hora (`schedule->hourly()`)
- Limpieza de tokens de recuperación de contraseña expirados: cada 24 horas (`schedule->daily()`)
- Limpieza de logs antiguos según TTL: cada día a las 2 AM (`schedule->dailyAt('02:00')`)
- Generación de reportes: cada semana a las 8 AM (`schedule->weeklyOn(1, '8:00')`)
- Backup de base de datos: cada día a las 3 AM (`schedule->dailyAt('03:00')`)
- Sincronización de índices de búsqueda: cada hora (`schedule->hourly()`)
- Verificación de salud de servicios: cada 5 minutos (`schedule->everyFiveMinutes()`)
- Sincronización con servicios externos: cada hora (`schedule->hourly()`)

**Configuración:**
- Definición de tareas en `app/Console/Kernel.php` usando `schedule()`
- Frecuencias apropiadas para cada tarea según criticidad
- Logging de ejecución de tareas en ActivityLog
- Manejo de errores en tareas con notificaciones
- Overlap prevention para tareas que no deben ejecutarse simultáneamente

---

## 10. WebSockets con Laravel Reverb (Opcional)

**Nota:** WebSockets no están incluidos por defecto. Se documenta cómo agregarlos cuando sean necesarios.

### 9.1 Configuración de Reverb

**Instalación:**
- Instalación de Laravel Reverb mediante `composer require laravel/reverb`
- Configuración de servidor WebSocket en `config/reverb.php`
- Configuración de broadcasting en `config/broadcasting.php`
- Variables de entorno: `REVERB_HOST`, `REVERB_PORT`, `REVERB_KEY`

**Configuración de Broadcasting:**
- Driver de broadcasting configurado como `reverb`
- Canales públicos: sin autenticación, acceso libre
- Canales privados: requieren autenticación JWT
- Canales de presencia: incluyen lista de usuarios presentes
- Autenticación de canales privados mediante middleware

### 9.2 Eventos de Broadcasting

**Eventos Base:**
- `UserLoggedInEvent` - Usuario se conectó, broadcast a canal de presencia
- `UserLoggedOutEvent` - Usuario se desconectó, actualiza canal de presencia
- `DataUpdatedEvent` - Datos fueron actualizados, broadcast a usuarios interesados
- `NotificationEvent` - Nueva notificación para usuario específico
- Todos los eventos implementan `ShouldBroadcast` interface

**Configuración de Canales:**
- Canales definidos en `routes/channels.php`
- Autorización de canales privados mediante `Broadcast::channel()`
- Canales de presencia configurados con información de usuario
- Middleware de autenticación para canales privados

### 9.3 Integración Frontend

**Documentación:**
- Ejemplos de conexión desde cliente JavaScript usando Laravel Echo
- Manejo de eventos con listeners
- Reconexión automática en caso de desconexión
- Manejo de errores y estados de conexión
- Endpoint `POST /api/auth/broadcast-token` para obtener token de autenticación

---

## 11. Búsqueda con Meilisearch (Opcional)

**Nota:** Meilisearch es opcional. Se incluye configuración básica pero puede omitirse si no se necesita búsqueda full-text.

### 10.1 Configuración de Meilisearch

**Instalación:**
- Instalación de Laravel Scout mediante `composer require laravel/scout`
- Configuración de driver Meilisearch en `config/scout.php`
- Instalación de driver Meilisearch: `composer require meilisearch/meilisearch-php`
- Configuración de URL y master key desde `.env`
- Batch size configurado: 500 documentos por batch

**Configuración de Índices:**
- Índice de usuarios con campos: name, email, phone
- Índice de logs para búsqueda en logs (opcional)
- Configuración de filtros y facetas por modelo
- Configuración de ranking personalizado
- Sincronización automática mediante observers

### 10.2 Modelos Searchable

**Implementación:**
- Trait `Searchable` aplicado a modelos que requieren búsqueda
- Método `toSearchableArray()` definido para especificar campos indexables
- Configuración de filtros mediante `getScoutFilters()`
- Sincronización automática en eventos `created`, `updated`, `deleted`
- Comando artisan para sincronización masiva: `php artisan scout:import`

**Búsqueda Avanzada:**
- Búsqueda full-text con typo tolerance habilitada
- Filtros por campos específicos (rol, estado, fecha)
- Ordenamiento personalizado por relevancia o campos
- Paginación de resultados con límite configurable
- Resaltado de matches en resultados

**API de Búsqueda:**
- `SearchController` con endpoint `GET /api/search?q=query&type=users,roles`
- Búsqueda global en múltiples modelos
- Búsqueda específica por modelo: `GET /api/users/search?q=query`
- Facetas por tipo de resultado
- Respuesta estructurada con metadatos de búsqueda

---

## 12. Infraestructura Docker

### 11.1 Servicios de Aplicación

**App Container (PHP 8.5 + Laravel):**
- Dockerfile basado en `php:8.5-fpm-bookworm`
- Extensiones instaladas: pdo_pgsql, redis, opcache, gd, intl, zip
- Instalación de Composer desde imagen oficial
- Copia de código de aplicación con optimización de layers
- Configuración de php.ini: memoria (256M), timeouts (60s), opcache habilitado
- Health check: `curl http://localhost:8010/api/health`
- User: www-data para seguridad
- Volúmenes para código y storage

**Reverb Container:**
- Configuración de servidor WebSocket Reverb
- Variables de entorno desde `.env`
- Health checks configurados
- Conexión a Redis para pub/sub
- Puerto: 8012 (host), 8080 (interno)

**Horizon Container:**
- Worker de colas configurado usando mismo Dockerfile que app
- Comando: `php artisan horizon`
- Variables de entorno compartidas
- Health checks para verificar workers activos
- Múltiples réplicas configurables

**Scheduler Container:**
- Ejecutor de tareas programadas mediante cron
- Configuración de cron en Dockerfile
- Comando: `php artisan schedule:work`
- Health checks para verificar ejecución
- Logging de tareas ejecutadas

### 11.2 Servicios de Base de Datos

**PostgreSQL Principal:**
- Imagen: `postgres:18-alpine`
- Puerto: 5432 (interno), 8011 (host)
- Base de datos: `apygg`
- Usuario y password desde `.env`
- Volúmenes persistentes para datos
- Scripts de inicialización en `docker/postgres/init/`
- Backups configurados mediante cron job
- Configuración optimizada para producción
- **Nota:** Los logs se almacenan en la misma base de datos `apygg` con particionamiento por mes para optimizar consultas y limpieza. Si en el futuro se requiere separar logs en otra base de datos, se puede migrar fácilmente siguiendo la documentación de migración.

**PgBouncer (Connection Pooler) - Opcional:**
- Imagen: `pgbouncer/pgbouncer:latest`
- Puerto: 6432 (interno), 8017 (host) - Puerto estándar de PgBouncer
- Modo: `transaction` (recomendado para Laravel)
- Pool size: `default_pool_size=25`, `max_client_conn=100`
- Configuración en `docker/pgbouncer/pgbouncer.ini`
- Autenticación mediante variables de entorno o `userlist.txt`
- Conexión a PostgreSQL: `postgres:5432` (servicio interno Docker)
- Health check: `pgbouncer -c "SHOW POOLS"`
- Perfiles: Solo `prod` (opcional en `dev` para pruebas)
- **Uso:** En producción, Laravel se conecta a PgBouncer (puerto 6432) en lugar de PostgreSQL directo
- **Ventajas:** Reduce conexiones directas a PostgreSQL, mejora rendimiento con alta carga
- **Nota:** En desarrollo se puede usar conexión directa a PostgreSQL sin PgBouncer

### 11.3 Servicios de Cache y Colas

**Redis:**
- Imagen: `redis:7-alpine`
- Puerto: 6379 (interno), 8014 (host)
- Persistencia configurada: RDB cada 60 segundos
- Políticas de evicción: allkeys-lru
- Volúmenes persistentes para datos
- Configuración de memoria máxima
- Health checks configurados

**Meilisearch:**
- Imagen: `getmeili/meilisearch:latest`
- Puerto: 7700 (interno), 8013 (host)
- Master key desde `.env`
- Volúmenes para índices persistentes
- Health checks configurados
- Configuración de límites de memoria

### 11.4 Servicios de Observabilidad

**Laravel Telescope (Solo Dev):**
- Telescope incluido en el contenedor de aplicación
- Dashboard accesible en `/telescope` solo en entorno de desarrollo
- Configuración automática para filtrar datos sensibles
- Retención configurable de datos históricos

**Nota sobre Observabilidad Avanzada:**
- Prometheus + Grafana + OpenTelemetry NO están incluidos por defecto
- Se documenta cómo agregarlos cuando se migre a microservicios
- Para monolitos, Telescope es suficiente para desarrollo y debugging

**Nota sobre Herramientas de Desarrollo:**
- PgAdmin y Redis Commander NO están incluidos en Docker para reducir peso
- Se recomienda usar herramientas de escritorio para gestión de bases de datos:
  - **PostgreSQL**: TablePlus, DBeaver, pgAdmin (desktop), o extensión PostgreSQL de VS Code
  - **Redis**: TablePlus, RedisInsight, o extensión Redis de VS Code
- Estas herramientas se conectan directamente a los servicios Docker expuestos en los puertos del host
- Documentación de conexión disponible en README.md

### 11.6 Docker Compose

**Archivo Principal (`docker-compose.yml`):**
- Definición de todos los servicios con configuración base
- Networks configuradas: `apygg-network` (bridge)
- Volúmenes definidos para persistencia
- Variables de entorno desde archivos `.env`
- Profiles para diferentes entornos: `dev`, `staging`, `prod`
- Dependencias entre servicios configuradas
- Restart policies configuradas

**Profiles:**
- Profile `dev`: Todos los servicios esenciales incluyendo Telescope para debugging
- Profile `prod`: Solo servicios esenciales, múltiples instancias de app para alta disponibilidad
- Staging puede usar profile `prod` con variables de entorno diferentes
- **Nota:** Para gestión de bases de datos, usar herramientas de escritorio (TablePlus, DBeaver) o extensiones de VS Code en lugar de servicios Docker

---

## 13. Migraciones y Seeders

### 13.1 Migraciones de Base de Datos Principal (`apygg`)

**Autenticación:**
- `create_users_table` - Tabla de usuarios con UUID como primary key, soft deletes, campos: name, email (único), password, phone, avatar, is_active, email_verified_at, last_login_at
- `create_password_reset_tokens_table` - Tokens de recuperación de contraseña con expiración
- `create_sessions_table` - Sesiones de usuarios para aplicación web
- `create_jwt_blacklist_table` - Tokens JWT revocados con jti (JWT ID), user_id, revoked_at, expires_at

**Autorización:**
- `create_roles_table` - Roles del sistema con UUID, name (único), display_name, description
- `create_permissions_table` - Permisos granulares con UUID, name (único), display_name, resource, action, description
- `create_role_permission_table` - Tabla pivot para relación muchos-a-muchos roles-permisos
- `create_user_role_table` - Tabla pivot para asignación de roles a usuarios
- `create_user_permission_table` - Permisos directos que sobrescriben roles, con granted_at

**Sistema:**
- `create_api_keys_table` - API keys para sistemas externos con UUID, user_id, name, token (hashed), last_used_at, expires_at
- `create_features_table` - Feature flags con name (único), enabled, description, config (JSON) - **NOTA:** Esta migración está documentada pero NO se ejecuta por defecto. Los feature flags se gestionan mediante archivo `config/features.php`. Ver sección 4.1 para detalles de migración futura.
- `create_cache_table` - Cache de aplicación para driver database
- `create_jobs_table` - Cola de trabajos con queue, payload, attempts, reserved_at, available_at
- `create_failed_jobs_table` - Jobs fallidos con uuid, connection, queue, payload, exception, failed_at
- `create_notifications_table` - Notificaciones en base de datos con notifiable_type, notifiable_id, data, read_at

**Logging (en misma base de datos con particionamiento):**
- `create_api_logs_table` - Logs de requests/responses con trace_id, user_id, request_method, request_path, request_query, request_body, request_headers, response_status, response_body, response_time_ms, user_agent, ip_address, created_at
- `create_error_logs_table` - Logs de errores con trace_id, user_id, exception_class, message, file, line, stack_trace, context, severity, resolved_at, created_at
- `create_security_logs_table` - Logs de seguridad con trace_id, user_id, event_type, ip_address, user_agent, details, created_at
- `create_activity_logs_table` - Logs de auditoría con user_id, model_type, model_id, action, old_values, new_values, ip_address, created_at
- Particionamiento por mes en tablas de logs para optimizar consultas y limpieza

**Índices:**
- Índices en foreign keys para performance
- Índices en campos de búsqueda frecuente (email, name)
- Índices compuestos para consultas complejas
- Índices únicos donde sea necesario
- Índices para búsqueda rápida por trace_id (único) en logs
- Índices por fecha (created_at) para limpieza eficiente de logs
- Índices por usuario (user_id) para consultas de auditoría

### 13.2 Seeders

**DatabaseSeeder (`database/seeders/DatabaseSeeder.php`):**
- Orquestador principal de seeders
- Orden de ejecución definido: Roles → Permisos → Usuarios
- Ejecución condicional según entorno (solo desarrollo)

**RoleSeeder (`database/seeders/RoleSeeder.php`):**
- Roles base: Admin (acceso total), User (acceso básico), Guest (solo lectura)
- Descripciones y configuraciones para cada rol
- Asignación de permisos base a roles

**PermissionSeeder (`database/seeders/PermissionSeeder.php`):**
- Permisos base del sistema con estructura `resource.action`
- Permisos por módulo: users (create, read, update, delete), roles (manage), auth (login, logout)
- Asignación inicial de permisos a roles según jerarquía

**UserSeeder (`database/seeders/UserSeeder.php`):**
- Usuario administrador por defecto: email `admin@apygg.local`, password `admin123` (cambiar en producción)
- Usuarios de prueba para desarrollo usando UserFactory
- Asignación de roles a usuarios de prueba
- Solo ejecutado en entorno de desarrollo

**TestDataSeeder (`database/seeders/TestDataSeeder.php`):**
- Seeder específico para generar datos de prueba realistas y completos
- Útil para testing manual, demos y desarrollo rápido
- Genera datos en múltiples tablas relacionadas para pruebas completas
- Ejecutable con: `php artisan db:seed --class=TestDataSeeder`
- Solo ejecutado en entornos de desarrollo y testing

**Datos Generados por TestDataSeeder:**
- **Usuarios:** 50-100 usuarios de prueba con datos realistas (nombres, emails, avatares)
- **Roles:** Roles adicionales de prueba (Manager, Editor, Viewer) además de los base
- **Permisos:** Permisos de prueba asignados a roles de prueba
- **Asignaciones:** Usuarios asignados a diferentes roles para pruebas de permisos
- **Logs de Ejemplo:** 
  - 20-30 registros de `api_logs` con diferentes métodos HTTP y códigos de respuesta
  - 10-15 registros de `error_logs` con diferentes niveles de severidad
  - 15-20 registros de `security_logs` con diferentes tipos de eventos
  - 30-40 registros de `activity_logs` simulando cambios en modelos
- **API Keys:** 5-10 API keys de prueba para diferentes usuarios
- **Notificaciones:** 20-30 notificaciones de ejemplo en diferentes estados

**Características del TestDataSeeder:**
- Usa Factories de Laravel para generar datos consistentes
- Relaciones correctas entre modelos (usuarios con roles, logs con usuarios, etc.)
- Datos variados pero realistas (nombres en español, emails válidos, etc.)
- Timestamps distribuidos en el tiempo (últimos 30 días) para pruebas de filtros por fecha
- Configurable mediante variables de entorno o parámetros del seeder
- Puede limpiar datos existentes antes de generar nuevos (opcional)

**Uso del TestDataSeeder:**
```bash
# Generar datos de prueba completos
php artisan db:seed --class=TestDataSeeder

# Limpiar y regenerar (si implementado)
php artisan db:seed --class=TestDataSeeder --fresh

# Solo generar usuarios y roles (si implementado con opciones)
php artisan db:seed --class=TestDataSeeder --only=users,roles
```

**Nota:** El TestDataSeeder es independiente del DatabaseSeeder principal y puede ejecutarse por separado cuando se necesiten datos de prueba rápidamente.

---

## 14. Testing

### 14.1 Configuración de Testing

**PHPUnit:**
- Configuración de `phpunit.xml` con entorno de testing separado
- Factories configuradas para todos los modelos principales
- Cobertura de código configurada con enfoque pragmático:
  - Cobertura mínima 80% en código crítico (auth, usuarios, permisos)
  - Tests esenciales primero, aumentar cobertura gradualmente
  - No perseguir 80% desde día 1, priorizar calidad sobre cantidad

**TestCase Base (`tests/TestCase.php`):**
- Setup y teardown comunes con RefreshDatabase trait
- Helpers para testing: `actingAs()`, `loginAs()`, `createUser()`, `createAdmin()`
- Métodos de aserción personalizados: `assertApiSuccess()`, `assertApiError()`, `assertPermissionDenied()`
- Traits reutilizables para tests comunes
- Seed automático de roles/permisos base antes de cada test

### 14.2 Tests Unitarios

**Core (`tests/Unit/Core/`):**
- Tests de clases base: BaseController, BaseModel, BaseRequest, BaseResource, BaseRepository
- Tests de servicios base: CacheService, LogService, NotificationService, SecurityService
- Tests de helpers: ApiResponse, DateHelper, SecurityHelper, StringHelper
- Tests de traits: HasUuid, LogsActivity, SoftDeletesWithUser, Searchable

**Servicios (`tests/Unit/Services/`):**
- Tests de AuthService: authenticate(), generateTokens(), refreshToken(), revokeToken()
- Tests de TokenService: generación, validación, revocación
- Tests de UserService: CRUD, asignación de roles/permisos
- Tests de RoleService y PermissionService

### 14.3 Tests de Integración

**Autenticación (`tests/Feature/Auth/`):**
- Tests de login: credenciales válidas, inválidas, usuario inactivo
- Tests de registro: registro exitoso, validaciones, duplicados
- Tests de logout: revocación de token, blacklist
- Tests de recuperación de contraseña: forgot password, reset password
- Tests de refresh token: renovación exitosa, token inválido

**Usuarios (`tests/Feature/Users/`):**
- Tests de CRUD de usuarios: crear, leer, actualizar, eliminar
- Tests de asignación de roles: asignar, remover, permisos efectivos
- Tests de permisos: verificación de acceso, políticas
- Tests de búsqueda de usuarios

**Sistema (`tests/Feature/System/`):**
- Tests de health checks: básico, detallado, servicios individuales
- Tests de logging: API logs, error logs, security logs, activity logs
- Tests de rate limiting: límites por endpoint, bloqueo después de exceder

### 14.4 Tests de Performance

**Carga:**
- Tests de carga básicos usando herramientas externas (Apache Bench, wrk)
- Identificación de bottlenecks mediante profiling
- Optimización basada en resultados de tests
- Tests de stress para verificar límites del sistema

---

## 15. Documentación

### 15.1 Documentación de API Interactiva

**Scramble para Documentación:**
- Instalación y configuración de `dedoc/scramble` para documentación automática
- Documentación generada automáticamente desde Form Requests y Resources
- Interfaz interactiva tipo Postman integrada
- Actualización automática sin configuración manual de Swagger
- Mejor DX que L5-Swagger para Laravel

**Características:**
- Documentación de todos los endpoints con descripción, parámetros, respuestas
- Ejemplos de requests/responses generados automáticamente
- Esquemas de datos (Resources) documentados automáticamente
- Autenticación y autorización documentadas
- Rate limits documentados por endpoint
- Endpoints agrupados por módulo

**Endpoints Documentados:**
- Autenticación completa: login, register, logout, refresh, me
- Gestión de usuarios: CRUD, roles, permisos, actividad
- Gestión de roles y permisos: CRUD, asignaciones
- Health checks: básico, detallado
- Endpoints de sistema: búsqueda, feature flags

### 15.2 Documentación de Arquitectura

**ARCHITECTURE.md:**
- Descripción general de la arquitectura del sistema
- Diagramas de componentes (C4 model)
- Flujos de datos principales: autenticación, request/response, logging
- Decisiones arquitectónicas documentadas (ADRs)
- Patrones utilizados: Repository, Service Layer, Factory, Observer
- Estructura de directorios explicada
- Convenciones de código y naming

### 15.3 README Principal

**Contenido:**
- Descripción del proyecto APYGG y su propósito
- Stack tecnológico completo (Laravel 12, PostgreSQL, Redis, etc.)
- Requisitos del sistema (Docker, memoria, espacio en disco)
- Instrucciones de instalación paso a paso
- Configuración de entornos (dev, staging, prod)
- Comandos útiles del Makefile documentados
- Guía de contribución con estándares de código
- Licencia del proyecto
- Enlaces a documentación adicional

### 15.4 Documentación de Desarrollo

**Guías:**
- Cómo agregar un nuevo módulo: estructura, registro, rutas
- Cómo agregar un nuevo endpoint: controlador, request, resource, tests
- Cómo agregar logging: tipos de log, uso de LogService
- Cómo agregar tests: estructura, ejemplos, mejores prácticas
- Convenciones de código: PSR-12, naming (snake_case para BD/atributos, camelCase para métodos/variables, PascalCase para clases), estructura
- Estándares de commits: formato, mensajes, scope
- Proceso de desarrollo: branches, PRs, code review

---

## 16. Configuraciones Adicionales

### 16.1 Configuración de Cache

**Estrategias:**
- Cache de queries frecuentes usando `CacheService::remember()`
- Cache de respuestas API para endpoints de solo lectura
- Cache de permisos de usuario para evitar consultas repetidas
- Invalidación inteligente basada en eventos de modelos

**Configuración:**
- Drivers disponibles: Redis (producción), file (desarrollo), database (fallback)
- TTL por tipo de dato: permisos (1 hora), queries (30 minutos), respuestas API (5 minutos)
- Tags para invalidación selectiva: `user:{id}`, `model:{type}:{id}`
- Configuración en `config/cache.php`

### 16.2 Configuración de Sesiones

**Driver:**
- Configuración de Redis para sesiones en producción
- Driver file para desarrollo local
- Lifetime configurable: 120 minutos por defecto
- Seguridad de cookies: httpOnly, secure (solo HTTPS), sameSite
- Configuración en `config/session.php`

### 16.3 Configuración de Archivos

**Storage:**
- Configuración de filesystem en `config/filesystems.php`
- Drivers: local (desarrollo), S3 (producción)
- Políticas de retención configuradas
- URLs públicas para assets estáticos
- Configuración de permisos de archivos

### 16.4 Configuración de Mail

**Drivers:**
- SMTP configurado para producción
- Mailtrap o log para desarrollo
- Queue de emails para procesamiento asíncrono
- Templates base usando Markdown de Laravel
- Logging de emails en desarrollo para debugging
- Configuración en `config/mail.php`

---

## 17. Makefile y Comandos Útiles

### 17.1 Comandos de Desarrollo

**Setup:**
- `make install` - Instalación inicial: composer install, npm install
- `make setup` - Configuración completa: .env, migraciones, seeders
- `make migrate` - Ejecutar migraciones en la base de datos principal (incluye tablas de logs con particionamiento)
- `make seed` - Ejecutar seeders (solo desarrollo)

**Docker:**
- `make up` - Levantar servicios Docker en profile dev
- `make down` - Detener servicios Docker
- `make restart` - Reiniciar servicios Docker
- `make logs` - Ver logs en tiempo real de todos los servicios
- `make shell` - Acceder a shell dentro del contenedor de app
- `make build` - Rebuild imágenes Docker

**Desarrollo:**
- `make test` - Ejecutar suite completa de tests
- `make test-unit` - Solo tests unitarios
- `make test-feature` - Solo tests de integración
- `make test-coverage` - Tests con reporte de cobertura
- `make lint` - Ejecutar linter de código (PHP CS Fixer)
- `make format` - Formatear código según PSR-12

**Base de Datos:**
- `make db-fresh` - Resetear base de datos y ejecutar migraciones
- `make db-backup` - Backup manual de base de datos
- `make db-restore` - Restaurar backup de base de datos
- `make db-seed` - Ejecutar seeders

**Cache y Optimización:**
- `make cache-clear` - Limpiar todo el cache (aplicación, configuración, rutas)
- `make optimize` - Optimizar aplicación: cache config, routes, views
- `make route-cache` - Cache de rutas para producción
- `make config-cache` - Cache de configuración

**Colas:**
- `make queue-work` - Iniciar worker de colas manualmente
- `make horizon` - Acceder a dashboard de Horizon
- `make queue-retry` - Reintentar jobs fallidos

---

## 18. Seguridad Adicional

### 18.1 Headers de Seguridad

**Middleware (`App\Http\Middleware\SecurityHeadersMiddleware`):**
- X-Frame-Options: DENY (previene clickjacking)
- X-Content-Type-Options: nosniff (previene MIME sniffing)
- X-XSS-Protection: 1; mode=block (protección XSS básica)
- Strict-Transport-Security: max-age=31536000 (solo HTTPS, fuerza HTTPS por 1 año)
- Content-Security-Policy: default-src 'self' (previene XSS avanzado)
- Referrer-Policy: strict-origin-when-cross-origin (control de referrer)

### 18.2 Validación de Datos

**Sanitización:**
- Limpieza de inputs HTML usando `strip_tags()` y `htmlspecialchars()`
- Validación estricta de tipos en Form Requests
- Validación de rangos para números y fechas
- Validación de formatos para emails, URLs, UUIDs
- Sanitización automática mediante middleware `SanitizeInput`

### 18.3 Protección contra Ataques Comunes

**Implementaciones:**
- Protección CSRF mediante tokens de Laravel (para web)
- Protección SQL Injection mediante Eloquent (prepared statements)
- Protección XSS mediante sanitización y escape de output
- Protección contra brute force mediante rate limiting en auth endpoints
- Protección contra DDoS mediante rate limiting a nivel de aplicación
- Validación de inputs contra inyección de comandos
- Protección contra path traversal en manejo de archivos

---

## 19. Optimizaciones de Performance

### 19.1 Optimizaciones de Base de Datos

**Índices:**
- Índices en todas las foreign keys para joins eficientes
- Índices en campos de búsqueda frecuente (email, name, created_at)
- Índices compuestos para consultas complejas (user_id + created_at)
- Análisis periódico de queries lentas mediante `EXPLAIN ANALYZE`
- Optimización de índices según uso real

**Queries:**
- Eager loading de relaciones para evitar N+1 queries
- Select específico de columnas cuando no se necesitan todas
- Paginación eficiente usando cursor pagination para grandes datasets
- Chunking para procesamiento de grandes volúmenes de datos
- Uso de consultas raw solo cuando sea necesario

### 19.2 Optimizaciones de Cache

**Estrategias:**
- Cache de queries costosas con TTL apropiado
- Cache de respuestas API para endpoints de solo lectura
- Cache de configuración y rutas en producción
- Cache de permisos de usuario para evitar consultas repetidas
- Invalidación inteligente basada en eventos

### 19.3 Optimizaciones de Código

**PHP:**
- Uso de opcache en producción para cache de bytecode
- Optimización de autoloader mediante `composer dump-autoload -o`
- Minimización de includes y requires
- Uso eficiente de memoria evitando cargar datos innecesarios
- Uso de generators para grandes datasets

**Laravel:**
- Cache de configuración en producción (`php artisan config:cache`)
- Cache de rutas en producción (`php artisan route:cache`)
- Cache de vistas en producción (`php artisan view:cache`)
- Optimización de service providers (solo cargar lo necesario)
- Uso de queues para operaciones pesadas

---

## 20. Backups y Recuperación

### 20.1 Sistema de Backups

**Backups Automáticos:**
- Backup diario de base de datos principal (`apygg`) a las 3 AM
  - Incluye todas las tablas: usuarios, roles, permisos, y logs (api_logs, error_logs, security_logs, activity_logs)
  - Las tablas de logs están particionadas, permitiendo backups incrementales eficientes
- Retención configurable: 7 días (diarios), 30 días (semanales), 90 días (mensuales)
- Compresión de backups usando gzip
- Almacenamiento en ubicación segura (S3, servidor remoto)
- Verificación de integridad de backups después de creación
- Notificaciones de fallos de backup mediante `NotificationService`

**Backups Manuales:**
- Comando artisan para backup manual: `php artisan backup:create`
- Restauración de backups: `php artisan backup:restore {backup_file}`
- Verificación de integridad antes de restaurar
- Listado de backups disponibles: `php artisan backup:list`

### 20.2 Estrategia de Recuperación

**Plan de Recuperación:**
- Procedimientos documentados paso a paso
- Tiempos de recuperación estimados (RTO): 1 hora para datos críticos
- Punto de recuperación objetivo (RPO): máximo 24 horas de pérdida de datos
- Pruebas periódicas de restauración (mensual)
- Documentación de procedimientos de disaster recovery
- Roles y responsabilidades definidos

---

## 21. CI/CD y Automatización

### 21.1 Pipeline de Integración Continua

**Configuración de CI/CD:**
- Configurar pipeline con GitHub Actions, GitLab CI o Jenkins
- Pipeline multi-etapa: lint → tests → build → deploy
- Ejecución automática en cada push a ramas principales
- Ejecución en Pull Requests con reportes de cobertura
- Notificaciones automáticas de estado (Slack, email, Discord)

**Etapas del Pipeline:**
- **Lint Stage**: Ejecutar PHP CS Fixer, PHPStan nivel 9, ESLint (si aplica)
- **Test Stage**: Tests unitarios y de integración con cobertura mínima 80%
- **Security Stage**: Escaneo de vulnerabilidades con Dependabot/Snyk
- **Build Stage**: Construcción de imágenes Docker y verificación
- **Deploy Stage**: Despliegue automático a staging/producción según rama

**Análisis Estático de Código:**
- PHPStan nivel 9 para análisis estático completo
- SonarQube o SonarCloud para análisis de calidad de código
- Detección de code smells, bugs y vulnerabilidades
- Métricas de complejidad ciclomática y deuda técnica
- Reportes de calidad en cada PR

**Escaneo de Vulnerabilidades:**
- Dependabot para dependencias de Composer y NPM
- Snyk para análisis profundo de vulnerabilidades
- OWASP Dependency Check para auditoría de seguridad
- Actualización automática de dependencias menores
- Alertas automáticas para vulnerabilidades críticas

### 21.2 Despliegue Automático

**Estrategias de Despliegue:**
- Blue-Green deployment para zero-downtime
- Canary deployments para rollouts graduales
- Feature flags para activación progresiva
- Rollback automático en caso de fallos

**Entornos de Despliegue:**
- Desarrollo: Auto-deploy en cada push a `develop`
- Staging: Auto-deploy en merge a `staging`
- Producción: Deploy manual con aprobación requerida
- Pre-producción: Deploy automático para smoke tests

**Automatización de Releases:**
- Versionado semántico automatizado (Semantic Release)
- Generación automática de CHANGELOG.md
- Creación automática de tags de Git
- Notificaciones de release a stakeholders

### 21.3 Pre-commit y Git Hooks

**Pre-commit Hooks:**
- Validación de sintaxis PHP antes de commit
- Ejecución de PHP CS Fixer automático
- Validación de mensajes de commit (Conventional Commits)
- Prevención de commits con `console.log` o `dd()`
- Verificación de que los tests pasan localmente

**Git Hooks Configurados:**
- `pre-commit`: Validaciones básicas y formateo
- `pre-push`: Ejecución de tests y análisis estático
- `commit-msg`: Validación de formato de mensajes
- `post-merge`: Instalación automática de dependencias

---

## 22. Internacionalización (i18n) - Preparado para Expansión

### 22.1 Configuración Base de Idioma

**Idioma por Defecto:**
- Configuración de idioma español (`es`) como predeterminado en `config/app.php`
- Estructura preparada para agregar más idiomas cuando sea necesario
- Archivos de traducción en `resources/lang/es/`
- Mensajes de validación en español

**Estructura Preparada para Multi-idioma:**
- Estructura de directorios lista: `resources/lang/{locale}/`
- Helpers de traducción configurados
- Sistema de detección de idioma preparado (no implementado por defecto)
- Documentación de cómo agregar idiomas adicionales cuando sea necesario

### 22.2 Manejo de Timezones (Básico)

**Configuración de Timezones:**
- Timezone por defecto configurado en `config/app.php`
- Helper `DateHelper` con métodos básicos de formateo
- Estructura preparada para almacenar preferencia de timezone por usuario
- Documentación de cómo implementar detección automática cuando sea necesario

**Nota sobre i18n Completo:**
- La implementación completa de multi-idioma agrega complejidad innecesaria al inicio
- Se implementa solo español por defecto
- Se documenta claramente cómo agregar más idiomas cuando sea necesario
- La estructura está preparada para facilitar la expansión futura

---

## 23. Webhooks y Eventos Externos (Opcional)

**Nota:** Esta sección es opcional y puede implementarse cuando sea necesario. Los webhooks no son parte del boilerplate base pero pueden agregarse como módulo adicional.

### 23.1 Sistema de Webhooks

**Arquitectura de Webhooks:**
- Modelo `Webhook` para almacenar configuraciones de webhooks
- Modelo `WebhookEvent` para registro de eventos enviados
- Modelo `WebhookDelivery` para tracking de entregas
- Cola dedicada para procesamiento asíncrono de webhooks

**Configuración de Webhooks:**
- Endpoints configurables por usuario/organización
- Eventos suscribibles: `user.created`, `user.updated`, `order.created`, etc.
- Headers personalizables por webhook
- Timeout y retry configurable por webhook
- Filtros de eventos (solo eventos específicos)

### 23.2 Seguridad de Webhooks

**Firma de Webhooks:**
- Firma HMAC-SHA256 de payloads usando secret compartido
- Header `X-Webhook-Signature` con firma
- Verificación de firma en endpoint receptor (documentación)
- Rotación de secrets sin interrumpir webhooks activos
- Validación de timestamp para prevenir replay attacks

**Autenticación:**
- API keys para autenticación de webhooks salientes
- Basic Auth opcional para endpoints protegidos
- OAuth 2.0 para webhooks de terceros
- Rate limiting por webhook endpoint

### 23.3 Entrega y Reintentos

**Estrategia de Entrega:**
- Envío asíncrono mediante colas de Laravel
- Timeout configurable (default: 30 segundos)
- Reintentos exponenciales: 1min, 5min, 15min, 1h, 6h
- Máximo de 5 intentos antes de marcar como fallido
- Dead letter queue para webhooks fallidos

**Tracking de Entregas:**
- Registro de cada intento de entrega con timestamp
- Código de respuesta HTTP almacenado
- Tiempo de respuesta registrado
- Payload enviado almacenado (opcional, para debugging)
- Estado: `pending`, `delivered`, `failed`, `retrying`

### 23.4 Dashboard y Monitoreo

**Dashboard de Webhooks:**
- Lista de webhooks configurados con estado
- Historial de eventos enviados con filtros
- Métricas: tasa de éxito, tiempo promedio de entrega
- Logs detallados de cada entrega
- Opción de reenvío manual de webhooks fallidos

**Alertas:**
- Alertas cuando tasa de fallo supera umbral (ej: 10%)
- Notificaciones de webhooks fallidos críticos
- Alertas de webhooks sin actividad por período prolongado
- Dashboard de salud de webhooks en tiempo real

---

## 24. API Keys y Autenticación Avanzada

### 24.1 Sistema de API Keys Avanzado

**Gestión de API Keys:**
- Modelo `ApiKey` con campos: `name`, `key` (hashed), `user_id`, `scopes`, `last_used_at`, `expires_at`, `rate_limit`
- Generación de keys seguras usando `Str::random(64)`
- Hash de keys antes de almacenar (bcrypt)
- Prefijo identificable para keys (`apygg_live_`, `apygg_test_`)

**Scopes y Permisos:**
- Sistema de scopes granulares: `users:read`, `users:write`, `orders:read`, etc.
- Asignación de múltiples scopes por API key
- Validación de scopes en middleware `CheckApiKeyScope`
- Scopes predefinidos por módulo
- Scopes personalizables por organización

**Rate Limiting por API Key:**
- Límites configurables por API key individual
- Límites por scope (ej: `users:read` = 1000/min)
- Tracking de uso por API key
- Headers de rate limit en respuestas: `X-RateLimit-Limit`, `X-RateLimit-Remaining`
- Alertas cuando se acerca al límite (80%, 90%, 100%)

### 24.2 Rotación y Gestión de Keys

**Rotación Automática:**
- Comando artisan para rotación: `php artisan api-keys:rotate {key_id}`
- Período de gracia con key antigua y nueva activas simultáneamente
- Notificación al usuario antes de expiración
- Revocación automática de keys expiradas
- Historial de rotaciones

**Gestión de Keys:**
- Dashboard para crear, listar, editar, revocar API keys
- Filtros por usuario, estado, fecha de creación
- Búsqueda de keys por nombre o prefijo
- Exportación de lista de keys (CSV, JSON)
- Auditoría de cambios en API keys

### 24.3 OAuth 2.0 y MFA (Módulos Opcionales)

**Nota sobre OAuth 2.0 y MFA:**
- Estos módulos NO están incluidos en el boilerplate base
- Se documenta cómo agregarlos cuando sean necesarios
- OAuth 2.0 es útil cuando actúas como proveedor de identidad
- MFA es un feature específico que se agrega según necesidades del proyecto

**Documentación de Expansión:**
- Guía para agregar OAuth 2.0 usando `laravel/passport` cuando sea necesario
- Guía para agregar MFA (TOTP, SMS OTP) cuando sea necesario
- Ejemplos de implementación como módulos opcionales

---

## 25. Caché Avanzado y Estrategias (Opcional)

**Nota:** Esta sección es opcional y contiene optimizaciones avanzadas de cache. El cache básico ya está cubierto en las secciones 2.3 (`CacheService`) y 16.1. Estas estrategias avanzadas pueden implementarse cuando se necesiten optimizaciones específicas.

### 25.1 Estrategias de Cache Avanzadas

**Cache Warming:**
- Comando artisan para pre-calentar cache: `php artisan cache:warm`
- Cache warming automático después de deployments
- Cache de datos frecuentemente accedidos
- Cache de configuraciones y permisos al inicio
- Cache de resultados de queries costosas

**Cache Tags Avanzados:**
- Tags jerárquicos: `user:123`, `user:123:permissions`, `user:123:roles`
- Invalidación en cascada: invalidar `user:123` invalida todos sus subtags
- Tags por modelo: `model:User:123`, `model:Order:456`
- Tags por relación: `user:123:orders`, `user:123:notifications`
- Invalidación selectiva por contexto

**Cache de Respuestas HTTP:**
- Middleware `CacheResponse` para cache de respuestas completas
- Headers `Cache-Control` y `ETag` para validación
- Vary headers para cache por usuario, idioma, timezone
- Cache de respuestas GET por ruta y parámetros
- Invalidación automática en operaciones POST/PUT/DELETE

### 25.2 CDN Integration

**Configuración de CDN:**
- Integración con Cloudflare para cache de assets estáticos
- Integración con AWS CloudFront para distribución global
- Cache de respuestas API en edge locations
- Purge de cache CDN mediante API
- Configuración de TTL por tipo de contenido

**Estrategias CDN:**
- Assets estáticos con cache largo (1 año)
- Respuestas API con cache corto (5 minutos)
- Invalidación automática de cache en actualizaciones
- Headers de cache optimizados por tipo de contenido
- Compresión gzip/brotli habilitada

### 25.3 Cache Invalidation Inteligente

**Invalidación Basada en Eventos:**
- Listeners de eventos de modelos para invalidación automática
- Invalidación cuando se crea/actualiza/elimina modelo relacionado
- Invalidación de cache de usuario cuando cambian permisos
- Invalidación de cache de listados cuando cambian filtros
- Invalidación de cache de búsquedas cuando se indexan nuevos datos

**Invalidación Programática:**
- Métodos helper: `CacheService::forgetUser()`, `CacheService::forgetModel()`
- Invalidación por tags: `CacheService::forgetTag('user:123')`
- Invalidación masiva: `CacheService::flushByPattern('user:*')`
- Invalidación condicional basada en reglas de negocio
- Logging de invalidaciones para debugging

### 25.4 Métricas y Monitoreo de Cache

**Métricas de Cache:**
- Hit rate por tipo de cache (queries, respuestas, config)
- Miss rate y tiempo de respuesta en misses
- Uso de memoria por tipo de cache
- Tiempo promedio de invalidación
- Distribución de TTLs

**Dashboard de Cache:**
- Visualización de métricas en tiempo real
- Gráficos de hit/miss rate por período
- Análisis de eficiencia de cache
- Alertas cuando hit rate baja de umbral (ej: 70%)
- Recomendaciones de optimización

---

## 26. Performance y Optimización Avanzada

**Nota:** Esta sección documenta optimizaciones avanzadas que pueden implementarse cuando sea necesario. El boilerplate base ya incluye optimizaciones esenciales en la sección 19. Estas optimizaciones avanzadas son específicas y deben aplicarse según necesidades reales del proyecto.

### 26.1 Consideraciones de Optimización Avanzada

**Optimizaciones de Base de Datos:**
- Análisis periódico de queries lentas con `EXPLAIN ANALYZE` cuando sea necesario
- Identificación de N+1 queries mediante Laravel Debugbar en desarrollo
- Connection pooling con PgBouncer para entornos de alta carga (opcional)
- Materialized views para agregaciones costosas cuando sea necesario

**Optimizaciones de Código:**
- Uso de generators para grandes datasets cuando sea necesario
- Profiling de memoria con Xdebug o Blackfire para identificar bottlenecks
- Optimización de algoritmos complejos basada en datos reales de producción

**Optimizaciones HTTP:**
- Compresión gzip/brotli configurada en FrankenPHP (Octane)
- HTTP/2 y HTTP/3 soportados nativamente por FrankenPHP
- Cursor pagination para grandes datasets cuando sea necesario

**Optimizaciones de Colas:**
- Batching y chunking de jobs cuando se procesen grandes volúmenes
- Dead letter queue ya configurada en sección 8.1
- Monitoreo de performance de jobs mediante Horizon (sección 8.2)

## 27. Consideraciones Finales

### 27.1 Principios de Diseño

**Reutilización:**
- El código core debe ser lo más genérico posible para maximizar reutilización
- Abstracciones bien definidas para facilitar extensión
- Patrones de diseño consistentes en toda la aplicación

**Escalabilidad:**
- Diseñado pensando en crecer sin refactorización mayor
- Arquitectura modular que permite escalar componentes independientemente
- Preparado para escalado horizontal mediante load balancing

**Seguridad:**
- Múltiples capas de protección desde el inicio
- Principio de menor privilegio en permisos
- Auditoría completa de acciones sensibles
- Validación y sanitización en todas las capas

**Observabilidad:**
- Todo debe ser loggeable y monitoreable
- Traces distribuidos para debugging
- Métricas para análisis de performance
- Alertas proactivas para problemas

**Performance:**
- Cache agresivo donde sea apropiado
- Índices en base de datos para queries frecuentes
- Redis para sesiones y cache
- Optimización continua basada en métricas

**Calidad:**
- Tests con enfoque pragmático: crítico primero, expandir gradualmente
- Code review obligatorio para cambios
- Linting y formateo automático
- Documentación actualizada

### 27.2 Estructura del Proyecto APYGG

El proyecto seguirá una estructura simple y práctica (estilo Laravel estándar):

```
apygg/
├── app/
│   ├── Http/
│   │   ├── Controllers/        # BaseController + controladores por dominio
│   │   │   ├── Controller.php  # BaseController
│   │   │   ├── Auth/          # AuthController, RegisterController
│   │   │   ├── Users/         # UserController
│   │   │   ├── Profiles/      # ProfileController
│   │   │   ├── Logs/          # ApiErrorStatsController
│   │   │   └── Health/         # HealthController
│   │   ├── Requests/          # BaseRequest + requests por dominio
│   │   ├── Resources/         # BaseResource + resources por dominio
│   │   └── Middleware/        # Middleware comunes
│   ├── Models/                # BaseModel + modelos organizados
│   │   └── Logs/              # Modelos de logs
│   ├── Services/              # Servicios reutilizables
│   │   └── Logging/           # Servicios de logging
│   ├── Traits/                # Traits reutilizables
│   ├── Logging/               # Clases de logging
│   ├── Listeners/             # Event listeners
│   └── Providers/             # Service providers
├── config/                     # Configuraciones
├── database/
│   ├── migrations/             # Migraciones DB principal (incluye logs con particionamiento)
│   └── seeders/                # Seeders
├── docker/                     # Configuración Docker
├── routes/
│   └── api/                    # Rutas por dominio (auth.php, users.php, etc.)
├── tests/                      # Tests
└── docs/                       # Documentación
```

### 27.3 Próximos Pasos

Una vez completado este plan de acción, el boilerplate APYGG estará listo para ser utilizado como base para nuevos proyectos. Cada sección debe ser implementada cuidadosamente, probada exhaustivamente y documentada adecuadamente.

El objetivo es crear una base sólida que pueda ser clonada y reutilizada, reduciendo significativamente el tiempo de desarrollo inicial y asegurando que todos los proyectos partan de una base robusta, segura y bien estructurada.

---

**Última actualización:** Enero 2026  
**Versión del Plan:** 1.0  
**Estado:** Plan completo y detallado listo para implementación

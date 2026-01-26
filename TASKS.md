# Lista de Tareas - API Boilerplate APYGG Laravel 12

## Fase 1: Setup Inicial y Configuración Base (Semana 1-2)

### 1.1 Preparación del Entorno con Docker
- [x] Crear `docker-compose.yml` básico con servicios:
  - [x] Servicio PHP 8.5 con extensiones (pdo_pgsql, redis, opcache, gd, intl, zip)
  - [x] Servicio PostgreSQL 18 para desarrollo
  - [x] Servicio Redis 7 para cache y colas
  - [x] Networks y volumes básicos
- [x] Crear `docker/app/Dockerfile` optimizado para desarrollo
- [x] Crear `.dockerignore` para optimización
- [x] Probar que todos los servicios inicien correctamente: `docker compose --profile dev up -d`
  - ✅ **Build exitoso**: Configuración DNS en docker-compose.yml (`network: host` y DNS explícitos) resolvió el problema
  - ✅ **Servicios corriendo**: `apygg_app`, `apygg_postgres`, `apygg_redis` están activos
  - ✅ **Postgres y Redis**: Healthy y funcionando correctamente
  - ✅ **App**: Corriendo (esperando instalación de Laravel en Fase 1.2)
  - ✅ **Networks y Volumes**: Creados correctamente (`apygg_network`, `apygg_pgdata`, `apygg_redisdata`)

### 1.2 Instalación del Proyecto Laravel
- [x] Crear proyecto Laravel 12 usando contenedor PHP:
  - [x] Instalado en directorio temporal y movido preservando archivos existentes
- [x] Configurar nombre del proyecto como `apygg` en `composer.json` (configurado: "apygg/apygg")
- [x] Establecer namespaces base: `App\Core`, `App\Modules`, `App\Infrastructure`, `App\Helpers` (configurados en autoload PSR-4)
- [x] Configurar autoloading PSR-4 en `composer.json` (configurado: App\\, App\\Core\\, App\\Modules\\, App\\Infrastructure\\, App\\Helpers\\, Database\\Factories\\, Database\\Seeders\\)
- [x] Generar APP_KEY con `php artisan key:generate` (generado: base64:iCH/uJimLpT0lA5OjcmanhnilbrDIDaOPh18U9wxP+E=)
- [x] Instalar Laravel Octane con FrankenPHP (instalado: v2.13.3, configurado OCTANE_SERVER=frankenphp en .env)
- [x] Verificar que la aplicación funcione en `http://localhost:8010` (✅ funcionando correctamente)

### 1.3 Estructura de Directorios
- [x] Crear estructura base `app/Http/`
  - [x] `Controllers/Controller.php` (BaseController - existe)
  - [x] `Controllers/Auth/` (carpeta creada)
  - [x] `Controllers/Users/` (carpeta creada)
  - [x] `Controllers/Profiles/` (carpeta creada)
  - [x] `Controllers/Logs/` (carpeta creada)
  - [x] `Controllers/Health/` (carpeta creada)
  - [x] `Requests/BaseFormRequest.php` (BaseRequest - creado)
  - [x] `Requests/Auth/` (carpeta creada)
  - [x] `Requests/Users/` (carpeta creada)
  - [x] `Resources/BaseResource.php` (BaseResource - creado)
  - [x] `Resources/Auth/` (carpeta creada)
  - [x] `Resources/Users/` (carpeta creada)
  - [x] `Middleware/` (carpeta creada - ForceJsonResponse creado, TraceId, RateLimitLogger, SecurityLogger, CacheControl, Idempotency se crearán en fases posteriores)
- [x] Crear estructura `app/Models/`
  - [x] `Model.php` (BaseModel - creado)
  - [x] `User.php` (existe)
  - [x] `Logs/` (carpeta creada)
- [x] Crear directorio `app/Services/`
  - [x] `Logging/` (carpeta creada)
- [x] Crear directorio `app/Traits/` (carpeta creada)
- [x] Crear directorio `app/Logging/` (carpeta creada)
- [x] Crear directorio `app/Listeners/Security/` (carpeta creada)
- [x] Crear directorio `app/Providers/` (ya existe)
- [x] Crear estructura `routes/`
  - [x] `api.php` (rutas principales - existe)
  - [x] `api/auth.php` (creado)
  - [x] `api/users.php` (creado)
- [x] Crear directorio `tests/Unit/`, `tests/Feature/` (existen)
- [x] Crear directorio `database/migrations/`, `database/seeders/` (existen)
- [x] Crear directorio `docker/` (ya existe)
- [x] Crear directorio `docs/` (ya existe)

### 1.4 Archivos de Configuración de Entornos
- [x] Crear `.env.example` base documentado (182 líneas, 73 líneas de comentarios, todas las variables necesarias documentadas)
- [x] Crear `dev.env.example` con debugging habilitado (40 líneas, solo variables Docker: DB_HOST, REDIS_HOST, MEILISEARCH_HOST, etc.)
- [x] Crear `staging.env.example` con valores cercanos a producción (36 líneas, solo variables Docker)
- [x] Crear `prod.env.example` con optimizaciones de seguridad (39 líneas, solo variables Docker, incluye PgBouncer)
- [x] Crear `.env` local para desarrollo (existe con APP_KEY generado: base64:iCH/uJimLpT0lA5OjcmanhnilbrDIDaOPh18U9wxP+E=)

### 1.5 Instalación de Dependencias Esenciales (Solo las Críticas)
- [x] Instalar dependencias mínimas para desarrollo inicial:
  - [x] `php-open-source-saver/jwt-auth` v2.8.3 para autenticación JWT básica (instalado: v2.8.3, configuración publicada: config/jwt.php)
  - [x] `laravel/octane` v2.13.3 con driver FrankenPHP (instalado: v2.13.4, configurado OCTANE_SERVER=frankenphp en .env)
- [x] Ejecutar `composer install` dentro del contenedor (composer install ejecutado, vendor/autoload.php existe, todas las dependencias instaladas)
- [x] NOTA: Otras dependencias (Reverb, Telescope, Scout, Horizon, etc.) se instalarán cuando sus servicios estén listos

---

## Fase 2: Configuración Inicial de Base de Datos (Semana 1-2)

### 2.1 Configuración de PostgreSQL Básica
- [x] Configurar conexión PostgreSQL principal en `config/database.php` (conexión 'pgsql' configurada)
- [x] Nombre de base de datos: `apygg` (configurado en .env: DB_DATABASE=apygg)
- [x] Configurar pool de conexiones optimizado (configurado: 'pool' => env('DB_POOL', 10) en config/database.php, DB_POOL=10 en .env)
- [x] Establecer timeout de conexión (configurado: 'timeout' => env('DB_TIMEOUT', 5) en config/database.php, DB_TIMEOUT=5 en .env)
- [x] Crear base de datos `apygg` en servidor PostgreSQL (Docker) (creada y verificada)
- [x] Verificar conectividad desde el contenedor (php artisan db:show funciona correctamente)

### 2.2 Configuración de PgBouncer (Connection Pooler)
- [x] Agregar servicio `pgbouncer` en `docker-compose.yml` con imagen oficial (configurado: imagen pgbouncer/pgbouncer:latest, perfil "prod")
- [x] Configurar PgBouncer para modo `transaction` (recomendado para Laravel) (configurado: PGBOUNCER_POOL_MODE=transaction)
- [x] Configurar pool size: `default_pool_size=25`, `max_client_conn=100` (configurado: PGBOUNCER_DEFAULT_POOL_SIZE=25, PGBOUNCER_MAX_CLIENT_CONN=100)
- [x] Crear archivo de configuración `docker/pgbouncer/pgbouncer.ini` (archivo creado y configurado)
- [x] Configurar autenticación con `userlist.txt` o variables de entorno (usando variables de entorno) (configurado: PGBOUNCER_AUTH_TYPE=md5, usando variables de entorno)
- [x] Exponer puerto 6432 (PgBouncer) en lugar de 5432 (PostgreSQL directo) para producción (puerto 8017:6432 configurado)
- [x] Actualizar variables de entorno: `DB_HOST=pgbouncer` para producción (configurado en env/prod.env.example: DB_HOST=pgbouncer, DB_PORT=6432)
- [x] Mantener conexión directa a PostgreSQL en desarrollo (sin PgBouncer) (configurado en env/dev.env.example: DB_HOST=postgres, DB_PORT=5432)
- [x] Documentar cuándo usar PgBouncer vs conexión directa (README.md creado) (documentación completa en docker/pgbouncer/README.md)
- [x] NOTA: PgBouncer es opcional pero recomendado para producción con alta carga

### 2.3 Primeras Migraciones Base (Esenciales)
- [x] Crear migración para tabla `users` con UUID como PK (creada: 2024_01_01_000001_create_users_table.php, UUID como PRIMARY KEY)
  - ⚠️ **Nota**: `sessions.user_id` es `bigInteger` (tabla del sistema Laravel), por lo que no hay relación directa con `users.id` (UUID). La tabla `sessions` se mantiene con `bigInteger` para compatibilidad.
- [x] Crear migración para tabla `roles` (creada: 2024_01_01_000002_create_roles_table.php, UUID como PRIMARY KEY)
- [x] Crear migración para tabla `permissions` (creada: 2024_01_01_000003_create_permissions_table.php, UUID como PRIMARY KEY)
- [x] Crear migración para tabla `role_permission` (pivot) (creada: 2024_01_01_000004_create_role_permission_table.php, ID como PK con FKs UUID)
- [x] Crear migración para tabla `user_role` (pivot) (creada: 2024_01_01_000005_create_user_role_table.php, ID como PK con FKs UUID)
- [x] Crear migración para tabla `user_permission` (creada: 2024_01_01_000006_create_user_permission_table.php, ID como PK con FKs UUID)
- [x] Ejecutar migraciones: `php artisan migrate` (todas las migraciones ejecutadas correctamente, batch [2])
- [x] Verificar que las tablas se crearon correctamente (todas las tablas existen: users con UUID, roles con UUID, permissions con UUID, role_permission, user_role, user_permission)

### 2.4 Configuración de Redis para Cache y Colas
- [x] Configurar Redis como driver en `config/cache.php` (default: redis) (configurado: CACHE_STORE=redis en .env, Redis disponible en stores)
- [x] Configurar Redis para sesiones en `config/session.php` (default: redis) (configurado: SESSION_DRIVER=redis en .env)
- [x] Configurar Redis como driver de colas en `config/queue.php` (default: redis) (configurado: QUEUE_CONNECTION=redis en .env)
- [x] Crear colas con prioridades: high, default, low (conexiones: redis-high, redis-default, redis-low) (creadas en config/queue.php: redis-high, redis-default, redis-low)
- [x] Configurar timeout (60 segundos) (retry_after: 60) (configurado: REDIS_QUEUE_RETRY_AFTER=60 en .env, aplicado a todas las conexiones Redis)
- [x] Configurar reintentos (3 intentos con backoff exponencial) (max_retries: 3, decorrelated_jitter) (configurado: REDIS_MAX_RETRIES=3, REDIS_BACKOFF_ALGORITHM=decorrelated_jitter en .env y config/database.php)
- [x] Probar conectividad Redis desde Laravel (comando: php artisan redis:test) (verificado: Redis funciona correctamente, ping exitoso)

### 2.5 Migraciones de Logs Básicas (Versión Simplificada)
- [x] Crear migración para tabla `logs_api` (sin particionamiento por ahora) (creada: 2024_01_01_000007_create_api_logs_table.php, ID como PK, trace_id UUID, user_id UUID, índices en trace_id, user_id, created_at)
- [x] Crear migración para tabla `logs_error` (sin particionamiento por ahora) (creada: 2024_01_01_000008_create_error_logs_table.php, ID como PK, trace_id UUID, user_id UUID, severity enum, índices en trace_id, user_id, severity, created_at)
- [x] Crear migración para tabla `logs_security` (sin particionamiento por ahora) (creada: 2024_01_01_000009_create_security_logs_table.php, ID como PK, trace_id UUID, user_id UUID, event_type enum, índices en trace_id, user_id, event_type, created_at)
- [x] Crear migración para tabla `logs_activity` (sin particionamiento por ahora) (creada: 2024_01_01_000010_create_activity_logs_table.php, ID como PK, user_id UUID, model_type/model_id, action enum, índices en user_id, model_type/model_id, action, created_at)
- [x] Crear índices básicos por created_at (índices creados en todas las tablas de logs: created_at, user_id, trace_id, y otros campos relevantes según tipo de log)
- [x] NOTA: Particionamiento avanzado se implementará en Fase 9
- [x] Ejecutar migraciones (todas las migraciones ejecutadas correctamente, batch [3], tablas creadas: logs_api, logs_error, logs_security, logs_activity)

### 2.6 Migraciones de Autenticación Básica
- [x] Crear migración para tabla `password_reset_tokens` (creada en 2024_01_01_000001_create_users_table.php, tabla del sistema Laravel)
- [x] Crear migración para tabla `jwt_blacklist` (creada: 2024_01_01_000011_create_jwt_blacklist_table.php, ID como PK, jti único, user_id UUID, expires_at, índices en jti, user_id, expires_at, created_at)
- [x] Crear migración para tabla `api_keys` (creada: 2024_01_01_000012_create_api_keys_table.php, UUID como PK, user_id UUID FK, name, key único, scopes JSON, last_used_at, expires_at, soft deletes)
- [x] Crear migración para tabla `sessions` (si se usa) (creada en 2024_01_01_000001_create_users_table.php, tabla del sistema Laravel)
- [x] Ejecutar migraciones (todas las migraciones ejecutadas correctamente, batch [4], tablas creadas: jwt_blacklist, api_keys; password_reset_tokens y sessions ya existían)

---

## Fase 3: Infraestructura Core - Componentes Base (Semana 3-4)

### 3.1 Implementación de Clases Base
- [x] Crear `BaseController` en `app/Core/Controllers/`
  - [x] Métodos CRUD base: index(), show(), store(), update(), destroy()
  - [x] Métodos de respuesta: sendSuccess(), sendError(), sendPaginated()
  - [x] Método loadRelations() para eager loading
  - [x] Manejo de paginación estándar
  - [x] Filtrado y ordenamiento
- [x] Crear `BaseRequest` en `app/Core/Requests/`
  - [x] Validación común de UUIDs, emails, fechas
  - [x] Método authorize() con políticas
  - [x] Sanitización automática
  - [x] Mensajes de error en español
- [x] Crear `BaseResource` en `app/Core/Resources/`
  - [x] Formato estándar de respuestas
  - [x] Método whenLoaded() para relaciones opcionales
  - [x] Manejo de metadatos
- [x] Crear `BaseModel` en `app/Core/Models/`
  - [x] Timestamps por defecto
  - [x] Soft deletes
  - [x] UUID como primary key
  - [x] Scopes comunes: active(), inactive(), recent(), oldest()

### 3.2 Implementación de Traits Reutilizables
- [x] Crear trait `HasUuid` en `app/Core/Traits/`
  - [x] Generación automática de UUID en evento creating
  - [x] Configuración de primary key
- [x] Crear trait `LogsActivity` en `app/Core/Traits/`
  - [x] Registro automático mediante Observers
  - [x] Captura de antes/después
  - [x] Filtrado de campos sensibles
- [x] Crear trait `SoftDeletesWithUser` en `app/Core/Traits/`
  - [x] Extiende soft deletes nativo
  - [x] Registro de usuario que eliminó
- [x] Crear trait `Searchable` en `app/Core/Traits/`
  - [x] Integración con Meilisearch
- [x] Crear trait `HasApiTokens` en `app/Core/Traits/`
  - [x] Métodos para crear, revocar, listar tokens

### 3.3 Reglas de Validación Reutilizables
- [x] Crear `ValidUuid` en `app/Core/Rules/`
- [x] Crear `ValidEmail` en `app/Core/Rules/`
- [x] Crear `ValidPhone` en `app/Core/Rules/`
- [x] Crear `ExistsInDatabase` en `app/Core/Rules/`
- [x] Crear `UniqueInDatabase` en `app/Core/Rules/`
- [x] Crear `StrongPassword` en `app/Core/Rules/`
- [x] Crear `ValidDateRange` en `app/Core/Rules/`
- [x] Crear `ValidJson` en `app/Core/Rules/`
- [x] Crear `ValidBase64Image` en `app/Core/Rules/`
- [x] Todos con mensajes de error en español

### 3.4 Implementación de Servicios Base
- [x] Crear `CacheService` en `app/Infrastructure/Services/`
  - [x] Métodos: get(), set(), forget(), remember()
  - [x] Tags para invalidación selectiva
  - [x] Método getAllMetrics()
- [x] Crear `LogService` en `app/Infrastructure/Services/`
  - [x] Métodos: log(), logApi(), logActivity(), logSecurity(), logError()
  - [x] Integración con Sentry
  - [x] Contexto enriquecido (trace_id, user_id, IP)
- [x] Crear `NotificationService` en `app/Infrastructure/Services/`
  - [x] Métodos para email, SMS, push, database
  - [x] Colas asíncronas
  - [x] Historial de notificaciones
- [x] Crear `SecurityService` en `app/Infrastructure/Services/`
  - [x] Encriptación/desencriptación
  - [x] Hashing de contraseñas
  - [x] Validación de IP whitelist
  - [x] Detección de comportamiento sospechoso
- [x] Crear `FileService` en `app/Infrastructure/Services/`
  - [x] Métodos: upload(), delete(), getUrl(), exists()
  - [x] Validación de archivos
  - [x] Manejo de imágenes

### 3.5 Helpers y Utilidades
- [x] Crear `ApiResponse` en `app/Helpers/`
  - [x] Métodos estáticos para respuestas estándar
  - [x] Formato RFC 7807 para errores
  - [x] Headers estándar incluidos
- [x] Crear `DateHelper` en `app/Helpers/`
  - [x] Formateo de fechas
  - [x] Conversión de timezones
  - [x] Cálculo de diferencias
- [x] Crear `SecurityHelper` en `app/Helpers/`
  - [x] Generación de tokens seguros
  - [x] Validación de contraseñas
  - [x] Sanitización de HTML
- [x] Crear `StringHelper` en `app/Helpers/`
  - [x] Slugs, truncamiento
  - [x] Conversión de casos
  - [x] Enmascaramiento de datos sensibles

---

## Fase 4: Manejo Global de Excepciones (Semana 4)

### 4.1 Exception Handler Personalizado
- [x] Crear/Modificar `app/Exceptions/Handler.php`
  - [x] Método render() personalizado (en bootstrap/app.php)
  - [x] Transformación automática a RFC 7807
  - [x] Manejo específico de excepciones comunes (404, 422, 500, etc.)
  - [x] Logging automático en ErrorLog
  - [x] Integración con Sentry para errores críticos
- [x] Crear excepciones personalizadas en `app/Exceptions/`
  - [x] `ApiException`
  - [x] `BusinessLogicException`
  - [x] `ExternalServiceException`
- [x] Configurar formato estándar de errores

---

## Fase 5: Sistema de Autenticación (Semana 5)

### 5.1 Configuración de JWT
- [x] Instalar y publicar configuración de `php-open-source-saver/jwt-auth`
- [x] Generar `JWT_SECRET` seguro
- [x] Configurar tiempos de expiración (access: 15 min, refresh: 7 días)
- [x] Crear migración y tabla `jwt_blacklist`
- [x] Configurar claims estándar (iss, aud, exp, iat, sub)
- [x] Implementar `JWTSubject` en modelo `User`
- [x] Configurar guard `api` con driver `jwt` en `config/auth.php`

### 5.2 Autenticación JWT - AuthController
- [x] Crear `app/Modules/Auth/Controllers/AuthController.php`
  - [x] `POST /auth/login` - Login con email/contraseña
  - [x] `POST /auth/register` - Registro (si está habilitado)
  - [x] `POST /auth/logout` - Logout y revocación
  - [x] `POST /auth/refresh` - Renovar token
  - [x] `GET /auth/me` - Datos del usuario autenticado
- [x] Crear `LoginRequest` y `RegisterRequest` con validaciones
- [x] Crear `AuthResource` para transformar respuestas

### 5.3 Servicios de Autenticación
- [x] Crear `TokenService` en `app/Modules/Auth/Services/`
  - [x] Generación de access tokens
  - [x] Generación de refresh tokens
  - [x] Validación de tokens
  - [x] Revocación de tokens
  - [x] Renovación con rotación
- [x] Crear `AuthService` en `app/Modules/Auth/Services/`
  - [x] Método authenticate($credentials)
  - [x] Método generateTokens($user)
  - [x] Método refreshToken($token)
  - [x] Método revokeToken($token)
  - [x] Manejo de intentos fallidos

### 5.4 Recuperación de Contraseña
- [x] Crear `PasswordController` en `app/Modules/Auth/Controllers/`
  - [x] `POST /api/auth/forgot-password`
  - [x] `POST /api/auth/reset-password`
  - [x] `POST /api/auth/change-password`
- [x] Crear `PasswordRequest` con validaciones (ForgotPasswordRequest, ResetPasswordRequest, ChangePasswordRequest)
- [x] Implementar lógica de tokens de reset con expiración (PasswordService)
- [x] Crear notificación de recuperación de contraseña (ResetPasswordNotification)

### 5.5 Rutas de Autenticación
- [x] Crear `routes/api/auth.php` con estructura modular
- [x] Registrar rutas en `routes/api.php` usando `require`
- [x] Aplicar middleware de rate limiting a endpoints de auth (5 req/min para públicos, 30 req/min para protegidos)

---

## Fase 6: Sistema RBAC (Semana 6)

### 6.1 Modelos de RBAC
- [x] Crear `Role` en `app/Modules/Users/Models/`
  - [x] Campos: id (UUID), name, display_name, description
  - [x] Relación con permissions (muchos-a-muchos)
  - [x] Relación con users (muchos-a-muchos)
  - [x] Métodos helper: hasPermission(), assignPermission(), removePermission()
- [x] Crear `Permission` en `app/Modules/Users/Models/`
  - [x] Campos: id (UUID), name, display_name, resource, action, description
  - [x] Relación con roles (muchos-a-muchos)
  - [x] Relación con users (muchos-a-muchos para permisos directos)
  - [x] Scopes: forResource(), forAction(), forResourceAndAction()
- [x] Agregar relaciones roles() y permissions() al modelo `User`

### 6.2 Servicios de RBAC
- [x] Crear `RoleService` en `app/Services/`
  - [x] Métodos CRUD para roles
  - [x] Método assignPermission()
  - [x] Método removePermission()
  - [x] Método syncPermissions()
  - [x] Cache y auditoría integrados
- [x] Crear `PermissionService` en `app/Services/`
  - [x] Métodos CRUD para permisos
  - [x] Métodos de validación (validateNameFormat, validateNameUnique, validateResourceAction)
  - [x] Filtros por recurso y acción
  - [x] Cache y auditoría integrados

### 6.3 Policies de Laravel
- [x] Crear `UserPolicy` en `app/Policies/`
  - [x] Métodos: viewAny, view, create, update, delete, restore, forceDelete
  - [x] Verificación de permisos con RBAC
  - [x] Logging de acciones autorizadas
- [x] Crear `RolePolicy` en `app/Policies/`
  - [x] Métodos: viewAny, view, create, update, delete
  - [x] Métodos adicionales: assignPermission, removePermission
  - [x] Protección especial para rol 'admin'
- [x] Crear `PermissionPolicy` en `app/Policies/`
  - [x] Métodos: viewAny, view, create, update, delete
  - [x] Solo administradores pueden crear/actualizar/eliminar
- [x] Crear `AuthServiceProvider` y registrar policies
- [x] Agregar métodos helper al modelo User (hasPermission, hasRole, isAdmin)

### 6.4 Middleware de RBAC
- [x] Crear `CheckPermission` middleware
  - [x] Verificación de permisos RBAC
  - [x] Soporte para múltiples permisos (OR)
  - [x] Logging de accesos denegados
- [x] Crear `CheckRole` middleware
  - [x] Verificación de roles RBAC
  - [x] Soporte para múltiples roles (OR)
  - [x] Logging de accesos denegados
- [x] Registrar middleware en `bootstrap/app.php` (Laravel 12)
  - [x] Alias 'permission' y 'role' registrados

### 6.5 Seeders de RBAC
- [x] Crear `RoleSeeder` con roles base: Admin, User, Guest
  - [x] Roles con descripciones y display names
- [x] Crear `PermissionSeeder` con permisos base
  - [x] Estructura recurso.accion (users.create, roles.read, etc.)
  - [x] Permisos para: users, roles, permissions, posts, categories, comments
  - [x] Display names y descripciones automáticas
- [x] Asignar permisos iniciales a roles según jerarquía
  - [x] Admin: Todos los permisos
  - [x] User: Permisos básicos (create, read, update, delete en posts/comments/categories)
  - [x] Guest: Solo lectura (read en todos los recursos)
- [x] Registrar en `DatabaseSeeder`

---

## Fase 7: Módulo de Usuarios (Semana 7)

### 7.1 Modelo User y Relaciones
- [x] Crear/Extender `User` en `app/Models/` (no se usa carpeta modules)
  - [x] Aplicar traits: HasUuids (nativo), LogsActivity, SoftDeletesWithUser, HasApiTokens
  - [x] Relaciones: roles(), permissions(), apiTokens(), activityLogs()
  - [x] Scopes: active(), inactive(), byEmail(), byRole()
  - [x] Métodos helper: isAdmin(), hasPermission(), hasAnyPermission(), hasAllPermissions(), hasRole(), hasAnyRole()

### 7.2 UserController y Endpoints
- [x] Crear `UserController` en `app/Http/Controllers/Users/`
  - [x] `GET /users` - Listar con paginación, filtrado, ordenamiento
  - [x] `GET /users/{id}` - Obtener usuario específico (con UserDetailResource)
  - [x] `POST /users` - Crear usuario (solo con permiso users.create)
  - [x] `PUT /users/{id}` - Actualizar usuario (con autorización por policy)
  - [x] `DELETE /users/{id}` - Eliminar usuario (soft delete, solo con permiso users.delete)
  - [x] `POST /users/{id}/restore` - Restaurar usuario (con permiso users.restore)
  - [x] `POST /users/{id}/roles` - Asignar roles (con permiso users.update)
  - [x] `DELETE /users/{id}/roles/{roleId}` - Remover rol (con permiso users.update)
  - [x] `GET /users/{id}/activity` - Historial de actividad
  - [x] Usa UserService para lógica de negocio
  - [x] Autorización con Policies
  - [x] Rutas configuradas en `routes/api/users.php`

### 7.3 Form Requests de Usuario
- [x] Crear `StoreUserRequest`
  - [x] Validación: email único, password fuerte, nombre requerido
  - [x] Validación de UUIDs para roles
  - [x] Sanitización automática (heredada de BaseFormRequest)
  - [x] Mensajes personalizados en español
- [x] Crear `UpdateUserRequest`
  - [x] Validación: email único (excepto si es el mismo usuario)
  - [x] Campos opcionales con `sometimes` y `nullable`
  - [x] Sanitización automática (heredada de BaseFormRequest)
  - [x] Mensajes personalizados en español
- [x] Crear `AssignRoleRequest`
  - [x] Validación de array de roles (mínimo 1, máximo 10)
  - [x] Validación de UUIDs y existencia de roles
  - [x] Validación de roles duplicados (`distinct`)
  - [x] Sanitización automática (heredada de BaseFormRequest)
  - [x] Mensajes personalizados en español

### 7.4 Resources de Usuario
- [x] Crear `UserResource` - Transformación básica
  - [x] Campos básicos: id, name, email, email_verified_at
  - [x] Relaciones opcionales: roles, permissions (solo si se cargan)
  - [x] Timestamps formateados en ISO 8601
  - [x] Oculta información sensible
- [x] Crear `UserDetailResource` - Con permisos y tokens
  - [x] Información completa del usuario
  - [x] Roles con descripción
  - [x] Permisos directos asignados
  - [x] Permisos efectivos (combinando roles + directos, sin duplicados)
  - [x] API Tokens (solo para propio usuario o admin)
  - [x] Historial de actividad (opcional con eager loading)
  - [x] Control de visibilidad según permisos
  - [x] Información adicional: is_admin, last_login_at

### 7.5 UserService y Lógica
- [x] Crear `UserService` en `app/Services/`
  - [x] Métodos CRUD: create(), update(), delete(), restore(), find()
  - [x] Gestión de roles: assignRoles(), removeRole()
  - [x] Gestión de permisos: assignPermissions(), removePermission()
  - [x] Búsqueda con filtros avanzados: list() con paginación, búsqueda por texto, filtro por rol/email
  - [x] Integración con CacheService (TTL 1 hora)
  - [x] Integración con LogService (auditoría de acciones)
  - [x] Validación de email único
  - [x] Hash automático de passwords
  - [x] Asignación de rol 'user' por defecto
  - [x] Protección contra remover último rol de admin
  - [x] Método getActivityLogs() para historial de actividad
  - [ ] Notificación de bienvenida (comentado, opcional para implementar con colas)

### 7.6 Rutas de Usuarios
- [x] Crear `routes/api/users.php`
- [x] Registrar rutas en `routes/api.php` con `require`
- [x] Aplicar middleware de autenticación (`auth:api`)
- [x] Aplicar middleware de autorización (`permission:`)
- [x] Prefijo `users` configurado correctamente
- [x] Rutas CRUD básicas: GET, POST, PUT, DELETE
- [x] Rutas adicionales: restore, assignRoles, removeRole, getActivity
- [x] Nombres de rutas asignados (route names)

### 7.7 Tests de Usuarios
- [x] Tests unitarios de UserService
- [x] Tests de integración para endpoints CRUD
- [x] Tests de permisos y roles

---

## Fase 8: Feature Flags (Semana 7)

### 8.1 Configuración de Feature Flags
- [x] Crear `config/features.php` con array de features
  - [x] Estructura simple: enabled, description
- [x] Crear helper `Feature` class
  - [x] Método estático `enabled()`
  - [x] Cache automático de configuración
- [x] Documentar cómo migrar a base de datos en el futuro

---

## Fase 9: Sistema de Logging y Auditoría (Semana 8)

### 9.1 Migraciones de Logs (ya creadas en Fase 2)
- [x] Verificar migraciones de: logs_api, logs_error, logs_security, logs_activity
  - [x] ✅ Todas las migraciones están creadas y ejecutadas correctamente
  - [x] ✅ Estructura correcta según estrategia del proyecto (ID auto-incrementable para logs)
  - [x] ✅ Índices correctos para optimización
  - [x] ✅ Verificación documentada en `docs/fase9-revision-migraciones-logs.md`

### 9.2 Modelos de Logs
- [x] Crear `ApiLog` en `app/Infrastructure/Logging/Models/`
  - [x] ✅ Modelo creado con scopes útiles (byTraceId, byUserId, byMethod, slowRequests, etc.)
  - [x] ✅ Usa ID auto-incrementable (NO UUID como primary key según estrategia)
  - [x] ✅ Campos UUID (trace_id, user_id) manejados como campos normales
- [x] Crear `ErrorLog` en `app/Infrastructure/Logging/Models/`
  - [x] ✅ Modelo creado con constantes de severidad
  - [x] ✅ Métodos helper (markAsResolved, isResolved)
  - [x] ✅ Scopes para filtrar por severidad y estado
- [x] Crear `SecurityLog` en `app/Infrastructure/Logging/Models/`
  - [x] ✅ Modelo creado con constantes de tipos de eventos
  - [x] ✅ Scopes para eventos críticos y sospechosos
  - [x] ✅ Método isCritical() para identificar eventos importantes
- [x] Crear `ActivityLog` en `app/Infrastructure/Logging/Models/`
  - [x] ✅ Modelo creado con relación polimórfica al modelo auditado
  - [x] ✅ Métodos helper (getChangedFields, hasChanges)
  - [x] ✅ Scopes para filtrar por acción (created, updated, deleted, restored)
- [x] **NO aplicar trait HasUuid** (correcto según estrategia: logs usan ID auto-incrementable)

### 9.3 Loggers Especializados
- [x] Crear `ActivityLogger` en `app/Infrastructure/Logging/Loggers/`
  - [x] ✅ Logger creado con métodos: log(), logCreated(), logUpdated(), logDeleted(), logRestored()
  - [x] ✅ Filtrado automático de campos sensibles (password, token, etc.)
  - [x] ✅ Captura de cambios antes/después (old_values, new_values)
  - [x] ✅ Listo para usar con Observers (ver Fase 9.4 para implementación de Observers)
- [x] Crear `AuthLogger` en `app/Infrastructure/Logging/Loggers/`
  - [x] ✅ Métodos: logLoginSuccess(), logLoginFailure(), logPasswordChanged(), logTokenRevoked()
  - [x] ✅ Registro de intentos de login con IP y user agent
  - [x] ✅ Detección automática de patrones sospechosos (múltiples fallos)
  - [x] ✅ Cache de intentos fallidos con TTL configurable
  - [x] ✅ Métodos helper: hasSuspiciousActivity(), getFailedAttempts(), clearFailedAttempts()
- [x] Crear `SecurityLogger` en `app/Infrastructure/Logging/Loggers/`
  - [x] ✅ Métodos: logPermissionDenied(), logSuspiciousActivity(), logAccountLocked(), logAccountUnlocked()
  - [x] ✅ Registro de eventos de seguridad con contexto completo
  - [x] ✅ Método genérico logEvent() para eventos personalizados
  - [x] ✅ Listo para usar en middleware (ver Fase 10 para implementación de middleware)
- [x] Crear `ApiLogger` en `app/Infrastructure/Logging/Loggers/`
  - [x] ✅ Método logRequest() para registrar requests/responses completos
  - [x] ✅ Sanitización automática de datos sensibles (headers, body, query)
  - [x] ✅ Cálculo automático de tiempo de respuesta
  - [x] ✅ Exclusión configurable de rutas (health, ping, telescope, etc.)
  - [x] ✅ Listo para usar en middleware (ver Fase 10 para implementación de middleware)

### 9.4 Configuración de Canales
- [x] ~~Crear canal `database_logs`~~ (NO necesario - decisión arquitectónica: logs van en tablas, no canal separado)
- [x] ~~Configurar `LogService` para usar canal database~~ (NO necesario)
- [x] Integración con Sentry para errores críticos
  - [x] ✅ Configurado canal `sentry` con niveles por entorno
  - [x] ✅ Dev: solo `critical` (evita spam en desarrollo)
  - [x] ✅ Staging/Prod: `error` y superior
  - [x] ✅ LogService actualizado para usar canal `sentry` automáticamente
- [x] Configurar niveles de log por canal y entorno
  - [x] ✅ Archivos (`single`/`daily`): dev=`debug`, staging/prod=`error`
  - [x] ✅ Sentry: dev=`critical`, staging/prod=`error`
  - [x] ✅ Slack: dev=`critical`, prod=`error`
  - [x] ✅ Configuración automática según `APP_ENV`

### 9.5 Tests de Logging
- [x] Tests de que los logs se registran correctamente
  - [x] Tests completos para ActivityLogger (created, updated, deleted, filtrado de campos sensibles)
  - [x] Tests completos para AuthLogger (login success/failure, password changed, detección de actividad sospechosa)
  - [x] Tests completos para SecurityLogger (permission denied, suspicious activity, account locked)
  - [x] Tests completos para ApiLogger (log request, exclusión de paths, sanitización de datos)
  - [x] Tests de modelos (ErrorLog, ActivityLog, SecurityLog, ApiLog)
  - [x] Tests de scopes y métodos helper
- [x] Tests de captura de contexto (trace_id, user_id, IP)

---

## Fase 10: Middleware y Seguridad (Semana 9)

### 10.1 Middleware Personalizados
- [x] Crear `TraceIdMiddleware` en `app/Http/Middleware/`
  - [x] Generación de UUID único por request
  - [x] Inyección en headers (X-Trace-ID)
- [x] Crear `SecurityLoggerMiddleware`
  - [x] Registro de eventos de seguridad
  - [x] Detección de patrones anómalos
- [x] Crear `RateLimitLoggerMiddleware`
  - [x] Registro de intentos bloqueados
  - [x] Alertas de abuso
- [x] Crear `CorsMiddleware`
  - [x] Configuración por entorno
  - [x] Whitelist de dominios
  - [x] Headers permitidos
- [x] ~~Crear `ApiVersionMiddleware`~~ (Cancelado: No se versionará la API)
  - [x] ~~Headers de versión~~ (Cancelado)
  - [x] ~~Routing condicional~~ (Cancelado)
- [x] Crear `TransformRequestMiddleware`
  - [x] Normalización de requests
- [x] Crear `TransformResponseMiddleware`
  - [x] Transformación de respuestas
- [x] Crear `SanitizeInput` middleware
  - [x] Limpieza de inputs

### 10.2 Configuración de CORS
- [x] Crear `config/cors.php`
  - [x] `allowed_origins` por entorno (dev, staging, prod: todos usan ALLOWED_ORIGINS desde env)
  - [x] `allowed_methods`, `allowed_headers` (GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD)
  - [x] `exposed_headers`, `max_age` (3600 segundos por defecto)
  - [x] `supports_credentials` (true por defecto)
- [x] Crear middleware `CorsMiddleware` en `app/Http/Middleware/`
- [x] Registrar middleware en `bootstrap/app.php`

### 10.3 Rate Limiting Adaptativo
- [x] Crear `config/rate-limiting.php`
- [x] Configurar límites por endpoint:
  - [x] Auth endpoints: 5 por minuto
  - [x] Lectura: 60 por minuto
  - [x] Escritura: 30 por minuto
  - [x] Admin: 10 por minuto
- [x] Headers informativos en respuestas
- [x] Implementar en middleware

### 10.4 Headers de Seguridad
- [x] Crear `SecurityHeadersMiddleware`
  - [x] X-Frame-Options, X-Content-Type-Options
  - [x] X-XSS-Protection, Strict-Transport-Security
  - [x] Content-Security-Policy, Referrer-Policy

### 10.5 Otros Middleware
- [x] Crear `IpWhitelist` middleware para endpoints críticos
- [x] Registrar todos los middleware en `bootstrap/app.php` (Laravel 12 usa bootstrap/app.php en lugar de Kernel.php)

---

## Fase 11: Health Checks y Monitoreo (Semana 9)

### 11.1 Health Check Endpoints
- [x] Crear `HealthController` en `app/Http/Controllers/Health/`
  - [x] `GET /health` - Health check básico sin autenticación (alias de ready)
  - [x] `GET /health/live` - Liveness probe (solo verifica que la app responde)
  - [x] `GET /health/ready` - Readiness probe (verifica servicios críticos: DB, Redis)
  - [x] `GET /health/detailed` - Health check completo (autenticado, verifica todos los servicios)
- [x] Implementar verificaciones de servicios:
  - [x] Database conectividad (con latencia en ms)
  - [x] Redis conectividad (con latencia en ms y prueba de read/write)
  - [x] Meilisearch (opcional, solo si está configurado)
  - [x] Horizon (opcional, solo si está instalado)
- [x] Respuestas rápidas y ligeras (timeout máximo 2-3 segundos)
- [x] Formato JSON estándar con status, timestamp y servicios
- [x] Excluidos de rate limiting y logging excesivo
- [x] Versión de aplicación en config/app.php

### 11.2 Configuración para Kubernetes
- [x] Documentar probes recomendadas (documentación completa en `docs/kubernetes-health-checks.md`)
- [x] Documentar timeouts y thresholds (valores recomendados por escenario: desarrollo, producción, alta carga)
- [x] Ejemplos de configuración de Deployment completo
- [x] Troubleshooting común y comandos útiles
- [x] Explicación de Liveness, Readiness y Startup probes

### 11.3 Laravel Telescope (Desarrollo)
- [x] Instalar y publicar Telescope (versión 5.16.1 instalada, migraciones ejecutadas)
- [x] Configurar dashboard en `/telescope` (path configurado, registrado en bootstrap/providers.php)
- [x] Filtros para datos sensibles (configurados en CacheWatcher y RequestWatcher: password, token, api_token, secret, key, authorization)
- [x] Deshabilitar en producción (configurado para habilitarse solo en local/dev, con variable TELESCOPE_ENABLED)
- [x] Rutas ignoradas: health*, telescope*, horizon* (configurado en ignore_paths)
- [x] Autorización configurada en AppServiceProvider (solo desarrollo)

---

## Fase 12: Procesamiento Asíncrono con Colas (Semana 10)

### 12.1 Configuración de Colas
- [ ] Configurar Redis como driver en `config/queue.php`
- [ ] Crear colas con prioridades: high, default, low
- [ ] Configurar timeout (60 segundos)
- [ ] Configurar reintentos (3 intentos con backoff exponencial)

### 12.2 Jobs Base
- [x] Crear `Job` en `app/Jobs/`
  - [x] Logging integrado
  - [x] Manejo de excepciones
  - [x] Retry automático
  - [x] Notificaciones de fallos

### 12.3 Jobs Específicos
- [ ] Crear `SendWelcomeEmailJob`
- [ ] Crear `SendPasswordResetEmailJob`
- [ ] Crear `SendNotificationJob`
- [ ] Crear `ProcessApiLogJob`
- [ ] Crear `ProcessActivityLogJob`

### 12.4 Laravel Horizon
- [ ] Instalar Horizon
- [ ] Configurar workers en `config/horizon.php`
- [ ] Configuración de colas por prioridad
- [ ] Dashboard accesible en `/horizon`

### 12.5 Scheduler de Tareas
- [ ] Configurar en `app/Console/Kernel.php`:
  - [ ] Limpieza JWT blacklist: cada hora
  - [ ] Limpieza tokens de reset: cada 24h
  - [ ] Limpieza de logs antiguos: diariamente
  - [ ] Generación de reportes: semanalmente
  - [ ] Backup de base de datos: diariamente
  - [ ] Sincronización de índices de búsqueda: cada hora
  - [ ] Verificación de salud: cada 5 minutos

---

## Fase 13: Sistema de Eventos y Listeners (Semana 10-11)

### 13.1 Estructura de Eventos
- [ ] Crear directorios: `app/Events/`, `app/Listeners/`
- [ ] Configurar `EventServiceProvider`

### 13.2 Eventos de Usuario
- [ ] Crear `UserCreated` event
- [ ] Crear `UserUpdated` event
- [ ] Crear `UserDeleted` event
- [ ] Crear `UserRestored` event
- [ ] Crear `UserLoggedIn` event
- [ ] Crear `UserLoggedOut` event

### 13.3 Eventos de Autorización
- [ ] Crear `RoleAssigned` event
- [ ] Crear `RoleRemoved` event
- [ ] Crear `PermissionGranted` event
- [ ] Crear `PermissionRevoked` event

### 13.4 Listeners
- [ ] Crear `LogUserActivity` listener
- [ ] Crear `LogAuthEvents` listener
- [ ] Crear `SendWelcomeEmail` listener
- [ ] Crear `InvalidateUserCache` listener
- [ ] Crear `InvalidatePermissionsCache` listener
- [ ] Registrar en `EventServiceProvider`

---

## Fase 14: API Keys y Autenticación Avanzada (Semana 11)

### 14.1 Modelo de API Keys
- [ ] Crear `ApiKey` en `app/Modules/Users/Models/`
  - [ ] Campos: name, key (hashed), user_id, scopes, last_used_at, expires_at
  - [ ] Prefijo identificable: `apygg_live_`, `apygg_test_`

### 14.2 Controlador de API Keys
- [ ] Crear `ApiKeyController`
  - [ ] `GET /api/v1/api-keys` - Listar keys del usuario
  - [ ] `POST /api/v1/api-keys` - Crear nueva key
  - [ ] `DELETE /api/v1/api-keys/{id}` - Revocar key
  - [ ] `POST /api/v1/api-keys/{id}/rotate` - Rotación de key

### 14.3 Middleware de API Keys
- [ ] Crear `AuthenticateApiKey` middleware
- [ ] Crear `CheckApiKeyScope` middleware
- [ ] Validación de scopes

### 14.4 Sistema de Scopes
- [ ] Definir scopes granulares: `users:read`, `users:write`, etc.
- [ ] Asignación de múltiples scopes por key
- [ ] Validación de scopes en endpoints

---

## Fase 15: Búsqueda con Meilisearch (Opcional, Semana 12)

### 15.1 Configuración de Meilisearch
- [ ] Instalar Meilisearch en Docker (si está disponible) (servicio configurado en docker-compose.yml)
- [ ] Instalar Laravel Scout y driver (ya instalados: laravel/scout ^10.17, meilisearch/meilisearch-php ^1.15)
- [ ] Configurar en `config/scout.php` (driver: meilisearch, batch size: 500)
- [ ] Configurar batch size y sincronización (chunk.searchable: 500, chunk.unsearchable: 500)

### 15.2 Modelos Searchable
- [ ] Aplicar trait `Searchable` a User
- [ ] Implementar `toSearchableArray()`
- [ ] Configurar filtros y facetas
- [ ] Sincronizar índices: `php artisan scout:import`

### 15.3 SearchController
- [ ] Crear endpoint `GET /api/v1/search`
- [ ] Búsqueda global en múltiples modelos
- [ ] Filtros y facetas

---

## Fase 2.5: Instalación de Dependencias Adicionales (Semana 2)

### 2.5.1 Dependencias de Observabilidad y Desarrollo
- [ ] Instalar `laravel/telescope` para observabilidad en desarrollo
- [ ] Instalar `spatie/laravel-query-builder` para filtros estandarizados
- [ ] Instalar `dedoc/scramble` para documentación automática de API
- [ ] Ejecutar `composer install`

### 2.5.2 Dependencias de Funcionalidades Avanzadas (Opcionales)
- [ ] Instalar `laravel/reverb` para WebSockets nativo (si se necesita)
- [ ] Instalar `laravel/scout` para búsqueda full-text (si se necesita)
- [ ] Instalar `laravel/horizon` para gestión de colas avanzada (si se necesita)
- [ ] Instalar `sentry/sentry-laravel` para logging de errores (opcional) (ya instalado: ^4.15, configurado en config/sentry.php)
- [ ] Ejecutar `composer install`

### 2.5.3 Configuración de FrankenPHP
- [ ] Configurar FrankenPHP para desarrollo y producción
  - [ ] Puerto configurable (PORT para PaaS como Railway)
  - [ ] SSL automático en producción (Let's Encrypt)
  - [ ] Compresión HTTP habilitada
  - [ ] Rate limiting a nivel de aplicación
- [ ] Probar FrankenPHP en contenedor

---

## Fase 16: Documentación de API Interactiva (Semana 13)

### 17.1 Instalación de Scramble
- [ ] Instalar `dedoc/scramble`
- [ ] Publicar configuración
- [ ] Configurar en `config/api.php`

### 17.2 Documentación Automática
- [ ] Verificar que todos los endpoints estén documentados
- [ ] Documentación de Form Requests
- [ ] Documentación de Resources
- [ ] Ejemplos de requests/responses
- [ ] Documentación de autenticación

### 17.3 Dashboard de Scramble
- [ ] Acceder a `/api/docs`
- [ ] Verificar que esté generada correctamente
- [ ] Probar endpoints interactivos

---

## Fase 17: Factories y Seeders para Testing (Semana 13)

### 18.1 Factories
- [ ] Crear `UserFactory` en `database/factories/`
  - [ ] Estados: admin(), inactive(), verified()
  - [ ] Relaciones: withRoles(), withPermissions()
- [ ] Crear `RoleFactory`
- [ ] Crear `PermissionFactory`
- [ ] Crear `ApiKeyFactory`
- [ ] Crear `ActivityLogFactory`
- [ ] Crear `SecurityLogFactory`

### 18.2 Seeders Base
- [ ] Crear `RoleSeeder` - Roles: Admin, User, Guest
- [ ] Crear `PermissionSeeder` - Permisos base del sistema
- [ ] Crear `UserSeeder` - Usuario admin y de prueba
- [ ] Actualizar `DatabaseSeeder` con orden correcto

### 18.3 TestDataSeeder
- [ ] Crear `TestDataSeeder` para datos realistas completos
  - [ ] 50-100 usuarios
  - [ ] Roles y permisos de prueba
  - [ ] Logs de ejemplo
  - [ ] API keys de prueba
  - [ ] Notificaciones de ejemplo
- [ ] Documentar uso y opciones

---

## Fase 18: Testing (Semana 14-15)

### 19.1 Configuración de Pest
- [x] Instalar Pest y pest-plugin-laravel
- [x] Configurar `tests/Pest.php` con helpers y expectativas
- [x] Migrar tests existentes de PHPUnit a Pest
- [x] Eliminar PHPUnit del proyecto
- [ ] Crear `TestCase` base mejorado en `tests/TestCase.php`
  - [ ] Setup y teardown comunes con RefreshDatabase trait
  - [ ] Helpers: actingAs(), loginAs(), createUser(), createAdmin()
  - [ ] Métodos de aserción personalizados: assertApiSuccess(), assertApiError()
  - [ ] Seed automático de roles/permisos base

### 19.2 Tests Unitarios
- [ ] Tests de BaseController
- [ ] Tests de BaseModel
- [ ] Tests de Traits
- [ ] Tests de Services: AuthService, UserService, etc.
- [ ] Tests de Helpers: ApiResponse, DateHelper, etc.
- [ ] Tests de Rules de validación

### 19.3 Tests de Integración
- [ ] Tests de Auth endpoints: login, register, logout, refresh
- [ ] Tests de CRUD de usuarios
- [ ] Tests de asignación de roles
- [ ] Tests de permisos y policies
- [ ] Tests de health checks
- [ ] Tests de rate limiting

### 19.4 Tests de Performance
- [ ] Tests de carga básicos
- [ ] Identificación de bottlenecks
- [ ] Profiling de queries lentas

### 19.5 Cobertura de Código
- [ ] Ejecutar tests con cobertura: `pest --coverage`
- [ ] Target inicial: 80% en código crítico
- [ ] Aumentar cobertura gradualmente
- [ ] Usar `--coverage-html` para reporte visual

---

## Fase 19: Configuraciones Adicionales (Semana 15)

### 20.1 Configuración de Cache
- [ ] Configurar Redis como driver en `config/cache.php`
- [ ] Configurar TTL por tipo de dato
- [ ] Configurar tags para invalidación
- [ ] Cache warming automático

### 20.2 Configuración de Sesiones
- [ ] Configurar Redis para sesiones
- [ ] Configurar lifetime (120 minutos)
- [ ] Seguridad de cookies: httpOnly, secure, sameSite

### 20.3 Configuración de Archivos
- [ ] Crear `config/files.php`
  - [ ] Límites de tamaño por tipo
  - [ ] Tipos MIME permitidos
  - [ ] Políticas de retención
- [ ] Crear `FileService` (ya creado en Fase 3)
- [ ] Crear `FileController` endpoints
- [ ] Crear modelo `File` y migraciones

### 20.4 Configuración de Mail
- [ ] Configurar driver SMTP en `config/mail.php`
- [ ] Queue para emails asíncrono
- [ ] Templates base en Markdown
- [ ] Configuración por entorno

---

## Fase 20: Seguridad Avanzada (Semana 16)

### 21.1 IP Whitelisting
- [ ] Crear `config/security.php`
- [ ] Middleware `IpWhitelist` para endpoints críticos
- [ ] Logging de intentos bloqueados

### 21.2 Encriptación de Datos Sensibles
- [ ] Identificar campos sensibles
- [ ] Implementar encriptación con Laravel Crypt
- [ ] Manejo de claves rotables

### 21.3 Protección contra Ataques
- [ ] CSRF tokens (para web si aplica)
- [ ] SQL Injection: verificar Eloquent
- [ ] XSS: sanitización verificada
- [ ] Brute force: rate limiting verificado

### 21.4 Validación de Inputs
- [ ] Revisar todas las Form Requests
- [ ] Validar rangos, tipos, formatos
- [ ] Pruebas de inputs maliciosos

---

## Fase 21: Optimizaciones de Performance (Semana 16)

### 22.1 Optimizaciones de Base de Datos
- [ ] Revisar índices en todas las tablas
- [ ] Eager loading verificado en endpoints
- [ ] Análisis de queries lentas con EXPLAIN
- [ ] Optimización de índices según uso real

### 22.2 Optimizaciones de Cache
- [ ] Cache de queries frecuentes
- [ ] Cache de respuestas API de solo lectura
- [ ] Cache de permisos de usuario
- [ ] Invalidación inteligente basada en eventos

### 22.3 Optimizaciones de Código
- [ ] Opcache habilitado en producción
- [ ] Composer dump-autoload -o
- [ ] Revisión de N+1 queries
- [ ] Profiling con Xdebug si es necesario

---

## Fase 22: Backups y Recuperación (Semana 17)

### 23.1 Sistema de Backups
- [ ] Crear comando artisan `backup:create`
- [ ] Comando `backup:restore`
- [ ] Comando `backup:list`
- [ ] Retención configurada: 7 días (diarios), 30 (semanales), 90 (mensuales)
- [ ] Scheduler para backups automáticos a las 3 AM
- [ ] Almacenamiento seguro (S3 o servidor remoto)
- [ ] Compresión con gzip

### 23.2 Verificación de Backups
- [ ] Test de restauración en entorno de staging
- [ ] Documentación de procedimientos

---

## Fase 23: CI/CD y Automatización (Semana 17-18)

### 24.1 Pipeline de CI
- [ ] Configurar en GitHub Actions / GitLab CI / Jenkins
- [ ] Etapa Lint: PHP CS Fixer, PHPStan nivel 9
- [ ] Etapa Tests: Tests unitarios + feature con cobertura
- [ ] Etapa Security: Dependabot, Snyk
- [ ] Etapa Build: Docker image build

### 24.2 Pre-commit Hooks
- [ ] Validación de sintaxis PHP
- [ ] PHP CS Fixer automático
- [ ] Validación de mensajes (Conventional Commits)
- [ ] Prevención de console.log, dd()
- [ ] Tests locales deben pasar

### 24.3 Despliegue Automático
- [ ] Blue-Green deployment
- [ ] Canary deployments con feature flags
- [ ] Rollback automático en caso de fallo
- [ ] Zero-downtime deployments

---

## Fase 24: Internacionalización (i18n) - Preparado para Expansión (Semana 18)

### 25.1 Configuración Base
- [ ] Español (`es`) como idioma por defecto
- [ ] Archivos de traducción en `resources/lang/es/`
- [ ] Mensajes de validación en español
- [ ] Documentación de cómo agregar idiomas

### 25.2 Manejo de Timezones
- [ ] Timezone por defecto en `config/app.php`
- [ ] Helper `DateHelper` con métodos de formateo
- [ ] Estructura preparada para preferencia de timezone por usuario
- [ ] Documentación de implementación futura

---

## Fase 25: Webhooks (Opcional, Semana 19)

### 26.1 Configuración de Webhooks
- [ ] Crear modelo `Webhook`
- [ ] Crear modelo `WebhookEvent`
- [ ] Crear modelo `WebhookDelivery`
- [ ] Eventos suscribibles definidos

### 26.2 Delivery y Reintentos
- [ ] Cola dedicada para webhooks
- [ ] Reintentos exponenciales
- [ ] Dead letter queue
- [ ] Tracking de entregas

### 26.3 Seguridad
- [ ] Firma HMAC-SHA256
- [ ] Validación de timestamp
- [ ] Rotación de secrets

### 26.4 Dashboard
- [ ] Endpoint para ver webhooks configurados
- [ ] Historial de entregas
- [ ] Reenvío manual de fallos

---

## Fase 26: WebSockets con Reverb (Opcional, Semana 19)

### 27.1 Instalación de Reverb
- [ ] Instalar `laravel/reverb`
- [ ] Configurar servidor WebSocket
- [ ] Configuración de broadcasting

### 27.2 Eventos y Channels
- [ ] Definir canales en `routes/channels.php`
- [ ] Crear eventos de broadcasting
- [ ] Implementar autorización de canales

### 27.3 Frontend Integration
- [ ] Documentación de conexión con Laravel Echo
- [ ] Ejemplos de listeners
- [ ] Reconexión automática

---

## Fase 27: Caché Avanzado (Opcional, Semana 20)

### 28.1 Cache Warming
- [ ] Comando artisan `cache:warm`
- [ ] Cache warming automático post-deployment
- [ ] Cache de datos frecuentes

### 28.2 CDN Integration
- [ ] Integración con Cloudflare (opcional)
- [ ] Cache de assets estáticos
- [ ] Purge automático de cache

### 28.3 Cache Invalidation Inteligente
- [ ] Listeners para invalidación automática
- [ ] Invalidación por tags
- [ ] Invalidación masiva por patrón

### 28.4 Métricas
- [ ] Monitoreo de hit rate
- [ ] Alertas cuando hit rate baja de 70%
- [ ] Recomendaciones de optimización

---

## Fase 28: Documentación Final (Semana 20-21)

### 29.1 ARCHITECTURE.md
- [ ] Descripción general de la arquitectura
- [ ] Diagramas de componentes (C4)
- [ ] Flujos de datos principales
- [ ] Decisiones arquitectónicas (ADRs)
- [ ] Patrones utilizados
- [ ] Estructura de directorios

### 29.2 README.md
- [ ] Descripción del proyecto
- [ ] Stack tecnológico
- [ ] Requisitos del sistema
- [ ] Instrucciones de instalación
- [ ] Configuración de entornos
- [ ] Comandos útiles
- [ ] Guía de contribución
- [ ] Licencia

### 29.3 Documentación de Desarrollo
- [ ] Cómo agregar un nuevo módulo
- [ ] Cómo agregar un nuevo endpoint
- [ ] Cómo agregar tests
- [ ] Convenciones de código
- [ ] Estándares de commits
- [ ] Proceso de desarrollo

### 29.4 Documentación de Operaciones
- [ ] Despliegue en diferentes entornos
- [ ] Configuración de Kubernetes (si aplica)
- [ ] Health checks y monitoreo
- [ ] Logs y debugging
- [ ] Performance tuning
- [ ] Disaster recovery

---

## Fase 29: Makefile y Comandos Útiles (Semana 21)

### 30.1 Creación de Makefile
- [ ] Setup: `make install`, `make setup`
- [ ] Docker: `make up`, `make down`, `make restart`, `make logs`
- [ ] Tests: `make test`, `make test-unit`, `make test-coverage`
- [ ] Código: `make lint`, `make format`
- [ ] Base de datos: `make db-fresh`, `make db-seed`
- [ ] Cache: `make cache-clear`, `make optimize`
- [ ] Documentación en Makefile con `make help`

---

## Fase 30: Testing Final e Integración (Semana 22)

### 31.1 Pruebas de Integración Completas
- [ ] Escenarios end-to-end
- [ ] Tests con datos realistas
- [ ] Pruebas de performance bajo carga
- [ ] Tests de seguridad (OWASP top 10)

### 31.2 Manual Testing
- [ ] Testing manual de todos los endpoints
- [ ] Verificación con herramientas como Postman/Insomnia
- [ ] Testing en diferentes navegadores (si aplica)

### 31.3 Load Testing
- [ ] Tests de carga con Apache Bench o wrk
- [ ] Identificación de límites de sistema
- [ ] Reporte de resultados

---

## Fase 31: Preparación para Producción (Semana 22-23)

### 32.1 Configuración de Producción
- [ ] `.env.production` con todas las variables necesarias
- [ ] Certificados SSL/TLS configurados
- [ ] HTTPS forzado
- [ ] Secrets seguros en variables de entorno

### 32.2 Optimizaciones Finales
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `composer install --no-dev`
- [ ] Comprobación de performance final

### 32.3 Seguridad Final
- [ ] Verificación de todos los headers de seguridad
- [ ] OWASP checklist completada
- [ ] Escaneo de vulnerabilidades
- [ ] Revisión de secretos (no expuestos)

### 32.4 Monitoreo
- [ ] Configuración de Sentry
- [ ] Configuración de Prometheus/Grafana (si aplica)
- [ ] Dashboards de monitoreo
- [ ] Alertas configuradas

---

## Fase 32: Despliegue y Lanzamiento (Semana 23-24)

### 33.1 Despliegue a Staging
- [ ] Despliegue a ambiente de staging
- [ ] Smoke tests
- [ ] Verificación de funcionalidad
- [ ] Tests de load en staging

### 33.2 Despliegue a Producción
- [ ] Último backup pre-deployment
- [ ] Despliegue usando blue-green o canary
- [ ] Verificación de health checks
- [ ] Monitoreo intensivo post-deployment
- [ ] Logs y alertas siendo monitoreados

### 33.3 Post-Launch
- [ ] Documentación de deployment
- [ ] Runbook para rollback si es necesario
- [ ] Debriefing y lecciones aprendidas
- [ ] Plan de mantenimiento futuro

---

## Notas Importantes:

✅ **Orden de Ejecución**: Cada fase está ordenada para minimizar dependencias  
✅ **Sin MVP**: Este proyecto es completo desde el inicio, no hay MVP  
✅ **Todas las Características**: Incluye auth, logging, tests, CI/CD, documentación, etc.  
✅ **Tiempo Estimado**: ~24 semanas para equipo de 2-3 personas  
✅ **Iteración**: Las fases pueden ejecutarse en paralelo cuando sea posible  

**Próximos Pasos:**
1. Comenzar con Fase 1 (Setup)
2. Establecer timeline realista según disponibilidad del equipo
3. Revisar y ajustar fases según necesidades específicas
4. Crear subtareas más granulares dentro de cada fase
5. Asignar responsabilidades del equipo

---

## 📝 Registro de Cambios Realizados

### Cambios en Fase 1:
- **Fase 1.1**: Movido Docker al inicio (era Fase 16)
- **Fase 1.2**: Creación de proyecto Laravel via contenedor Docker
- **Fase 1.5**: Solo dependencias críticas, resto movido a Fase 2.5

### Cambios en Fase 2:
- **Fase 2**: Configuración temprana de PostgreSQL y Redis
- **Fase 2.2**: Migraciones básicas primero, avanzadas después
- **Fase 2.3**: Redis configurado temprano para soporte de dependencias
- **Fase 2.4**: Logs básicos, particionamiento avanzado en Fase 9
- **Fase 2.5**: Dependencias adicionales escalonadas

### Eliminaciones:
- **Fase 16 original**: Eliminada (Docker movido a Fase 1.1)
- **Fases renumeradas**: 17-33 → 16-32

### Beneficios de los Cambios:
✅ **Desarrollo sin PHP local**: Docker disponible desde el inicio
✅ **Dependencias escalonadas**: Solo lo esencial primero
✅ **Servicios temprano**: PostgreSQL y Redis disponibles para Fase 1
✅ **Migraciones lógicas**: Básico primero, avanzado después
✅ **Flujo más realista**: Elimina dependencias imposibles


# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Arranque de proyecto nuevo: `make init NAME=<slug>` (compose.env y .env con puertos, contraseñas y claves propios; renombra composer/package; `origin` -> `upstream`), `make setup`, `make doctor`, `make fresh`, `make upgrade-check`, `make wt-env` (entorno por worktree) y `docs/new-project.md`. `make validate` ahora comprueba puertos repetidos, `REDIS_PASSWORD` vacío y puertos de BD/Redis/S3/Mailpit abiertos al exterior
- `APP_TITLE` / `config('app.title')`: nombre visible de la API (ficha de `/` y título de `/docs/api`)
- Perfil `dev` de Compose con Mailpit y un emulador S3 (RustFS; MinIO ya no publica imágenes) con buckets público y privado creados solos; targets `up-dev`, `mail-ui`, `mail-clear`, `s3-ui`, `s3-init`, `s3-test`; suite `Integration` (`make test-integration`); `docs/local-services.md` (sustituye a `docs/minio-configuration.md`)
- `php artisan storage:check`: prueba los discos de archivos configurados (sustituye a `test:s3` y `minio:test`)
- CHANGELOG.md con formato Keep a Changelog
- CONTRIBUTING.md con guías de contribución
- ARCHITECTURE.md con documentación de arquitectura
- Soporte base para multi-tenancy
- Stubs personalizados para Laravel
- Sistema de correo multi-cuenta con registro de envíos y webhook de entrega (`MailService`, `/admin/mail`)
- Gateway LLM agnóstico del proveedor, con áreas, modelos de respaldo y auditoría (`app/Services/LLM`, `/admin/llm`)
- Asistente de documentación con RAG sobre los Markdown del proyecto (`/docs/search`, `/docs/ask`)
- Menú dinámico administrable (`/admin/menu`)
- Auditoría de seguridad, lista de bloqueo y escáner de seguridad (`/admin/security/scans`, `/admin/blacklist`)
- Back-office interno opcional con Filament 5 (`/backoffice`, panel `backoffice`): usuarios, roles y permisos, logs de actividad/API/seguridad, correo (cuentas, plantillas, envíos), ajustes, lista negra y widget de salud. Acceso por sesión web con el permiso nuevo `backoffice.access` y MFA (TOTP) obligatorio; usa los mismos servicios y policies que la API. `BACKOFFICE_ENABLED=false` lo apaga; `docs/backoffice.md` explica cómo quitarlo
- Migración `2026_11_19_000030` (columnas cifradas del MFA por app en `users`)
- Migración `2026_11_19_000040`: las columnas `json` pasan a `jsonb`; regla: migraciones nuevas con `jsonb` (`JsonbColumnsTest`)
- `docs/upgrading.md`: notas de actualización de 1.0.0 a la versión actual
- `RoleService::assertCanGrantPermissions` y `MailTemplateConfig::toggleActive`: la lógica que estaba en un controller pasa a un sitio que comparten la API y el back-office

### Changed
- Todas las imágenes de Compose con versión fija (PgBouncer ya no usa `latest`)
- `chillerlan/php-qrcode` baja a `^5.0` (Filament 5 lo exige; `QrCodeService` ya usaba la API v5) y `composer update -W` sube `laravel/framework` a 13.43 y dependencias de Symfony
- `post-autoload-dump` publica los assets de Filament (`filament:upgrade`); no se versionan (`public/{css,js,fonts}/filament`)
- `bootstrap/app.php`: las rutas del back-office y de Livewire no pasan por el render JSON de la API
- `redact_key_fragments` incluye `recovery_codes` (códigos de recuperación del MFA)
- RBAC migrado a `spatie/laravel-permission` 8.5 (antes propio): `Role`/`Permission` extienden los del paquete, `User` usa `HasRoles` (guard `api`), middlewares `permission:`/`role:`/`role_or_permission:` del paquete con permiso explícito y guard en cada ruta (se elimina el mapa método HTTP → acción y `CheckPermission`/`CheckRole`). Migraciones nuevas `2026_11_19_000002` y `000003` (ver `docs/permissions.md`). Teams preparado y apagado (`PERMISSION_TEAMS`, columna `tenant_id`)
- Super admin por `Gate::after` (rol `admin`); `User::hasPermission/hasRole propios` sustituidos por `can()`/`hasPermissionTo()`/`hasRole()` de Spatie
- Semillas limpias: `PermissionSeeder` con solo los permisos que usa el código (test que lo vigila), `RoleSeeder` (admin, manager, user), `AdminUserSeeder` desde `ADMIN_EMAIL`/`ADMIN_PASSWORD` y `DemoDataSeeder` solo local
- `TRUSTED_PROXIES` configurable (vacío = no se confía en `X-Forwarded-*`)
- Octane: `FlushUploadedFiles` activo y `DisconnectFromDatabases` configurable con `OCTANE_DISCONNECT_DATABASES`
- Rutas `test/sentry/*` solo en `local` y `testing`
- `composer.json`: descripción Laravel 13 y sin el autoload `App\Modules` inexistente
- Makefile partido en `make/*.mk` por tema; el `Makefile` raíz solo define variables e incluye esos archivos

### Removed
- Código muerto: `app/DTOs`, `app/Enums`, `app/Events/Broadcasting`, helpers `StringHelper`/`DateHelper`/`SecurityHelper`, jobs `ProcessActivityLogJob`/`ProcessApiLogJob`/`SendNotificationJob`, ocho reglas de validación sin uso, `FcmService`, `QrCodeService`, `DeviceTokenController`/`DeviceToken`, `App\Exceptions\Handler`
- Login social: `SocialAuthController`, `SocialAuthService` y el paquete `laravel/socialite` (no tenía rutas, config ni tabla)
- `LogAuthEvents`: duplicaba el registro de login/logout; ahora `AuthService` dispara `Login`/`Logout` de Laravel y `LogAuthenticationEvents` deja un único registro
- Middlewares `SanitizeInput`, `TransformRequestMiddleware` y `TransformResponseMiddleware` (LOG-16, EST-38): quitaban HTML y saltos de línea, convertían `"no"`/`"1"`/`status=active` en booleanos, copiaban claves anidadas a la raíz y alteraban contraseñas; el de respuestas era inerte. La entrada llega tal cual (con `TrimStrings` y `ConvertEmptyStringsToNull` de Laravel)
- `BaseFormRequest::sanitizeInput()`: duplicaba esos middlewares estándar (y recortaba contraseñas)
- `database/seeders/initial_data.sql` (datos de otra app con contraseña "1234") y `UserSeeder`; permisos de ejemplo `posts.*`, `comments.*`, `system.backup`, `system.users` y los roles `guest`, `moderator`, `editor` que ningún código usaba
- Código de otra app que no pertenece al boilerplate: modelos y políticas de Wallet, Gift, UserMatch y Message, controladores de chat y tienda, sus Form Requests y `routes/api/chat.php`
- `SendPasswordResetEmailJob`, que no se usaba

### Fixed
- Webhooks salientes: la cola `webhooks` no la consumía ningún supervisor de Horizon (nunca se enviaban en producción); añadido `supervisor-webhooks` en todos los entornos y test que exige consumidor para cada cola usada
- `retry_after` (660 s) ya supera el timeout de cualquier job; `JOB_TIMEOUT`, `SECURITY_SCAN_TIMEOUT`, `WEBHOOK_MAX_TIMEOUT` (120 s, antes 300) y `WEBHOOK_JOB_TIMEOUT` (150 s) configurables por `.env`
- `SendWebhookJob`: un solo mecanismo de reintento (redespacho propio); 4xx del destino (salvo 408/429) es fallo permanente sin reintento
- `MailWebhookController`: inserción atómica del evento (captura de la violación única) en vez de `exists()` + `create()`; dos entregas iguales simultáneas dan 200 y una fila
- Guard explícito en vez de `auth()` sin guard (RoleController, loggers, `LogService`, `SoftDeletesWithUser`, Filament); nuevo helper `App\Helpers\Actor` y regla de ArchTest
- `OCTANE_SERVER` por defecto `frankenphp` en `config/octane.php`
- Eliminada `ExternalServiceException` (nadie la lanzaba)
- `CheckPermission` y `CheckRole` usan el guard `api`: con el guard por defecto (`web`) podían dar 401/403 intermitentes bajo Octane
- Listeners registrados dos veces por el autodescubrimiento (`withEvents(discover: false)`)
- `TransformRequestMiddleware` alteraba cadenas como `"007"`, teléfonos con cero inicial, `"1e5"` o números de más de 15 dígitos
- Import de `WebhookStatusEnum` en `UpdateWebhookRequest`
- Import roto en `WarmCacheCommand`: `cache:warm` fallaba

## [1.0.0] - 2026-01-27

### Added
- Sistema de autenticación JWT completo (login, register, logout, refresh, forgot-password)
- Sistema de roles y permisos con Spatie Permission
- Gestión de usuarios con CRUD completo
- Sistema de API Keys con scopes y rotación
- Sistema de Webhooks con reintentos automáticos
- Subida y gestión de archivos (local, S3, GCS)
- Notificaciones push (FCM) con Device Tokens
- Configuración de aplicación (Settings)
- Logging avanzado (API logs, Security logs, Activity logs)
- Health checks para monitoreo

### Middlewares
- Rate Limiting adaptativo
- CORS configurable
- Security Headers
- IP Whitelist
- ETag para caching
- TraceId para trazabilidad
- Response Compression

### Infraestructura
- Docker con FrankenPHP y Octane
- PostgreSQL 18 con soporte UUID
- Redis para cache y colas
- Laravel Horizon para monitoreo de colas
- Laravel Reverb para WebSockets
- Laravel Telescope para debugging
- Sentry para error tracking

### Documentación
- API auto-generada con Scramble (Swagger/OpenAPI)
- Documentación completa de endpoints

### Patrones de Diseño
- Repository Pattern para desacoplamiento de datos
- DTOs para tipado fuerte
- Enums centralizados
- Service Layer para lógica de negocio
- Contracts/Interfaces para inyección de dependencias
- Base classes (Model, Controller, FormRequest, Resource)

### CI/CD
- Pipeline CI con GitHub Actions
- Pipeline CD con deploy automático
- SonarCloud para análisis de código
- Dependabot para actualización de dependencias

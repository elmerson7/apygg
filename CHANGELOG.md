# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Revisión de aislamiento 4.3-B: la lista negra corta las sesiones vivas (JWT y API keys); canal de presencia por tenant sin correo; `security.allow_public_sharing` se aplica (403 `public_sharing_disabled`); búsqueda Scout filtrada por `tenant_id`; reglas `TenantExists`/`TenantUnique`; `Sequence` rechaza tenants explícitos ajenos; tope de empresas por usuario bajo bloqueo; `tenancy:assign-orphans` solo asigna filas de miembros; resincronización de roles `tenant-*` al activar equipos; jobs de tenants no activos no se ejecutan; `PlanLimits` y `SequenceExhausted` como excepciones de dominio. Ver `docs/upgrading.md`
- Redacción unificada de datos personales y secretos en logs y Sentry: `Redactor` con claves, patrones (email, tarjeta/Luhn, teléfono, JWT, Bearer), modo `mask`/`remove` y límites; aplicado por processor de Monolog en todos los canales, modelos de log de BD, `before_send`/`before_breadcrumb` de Sentry y `Pretty::json()`. Ver `docs/logging.md`
- Imagen base actualizada a `dunglas/frankenphp:1.13.6-php8.5.13-bookworm` (antes `1.11-php8.5-bookworm`: PHP 8.5.3, FrankenPHP 1.11.3). PHP 8.5.12 corrige dos vulnerabilidades críticas de ejecución remota sin autenticación (CVE-2026-109618 y CVE-2026-109619); 8.5.13 es la última 8.5.x. La etiqueta queda fijada a versión exacta para builds reproducibles. Proyectos derivados: ver `docs/upgrading.md` ("Actualizar la imagen por seguridad")

### Changed
- PDF: se quitan `barryvdh/laravel-snappy`, `setasign/fpdf` y `setasign/fpdi` (sin uso) y el binario `wkhtmltopdf` de la imagen Docker; el servicio único es `PdfRendererInterface` (dompdf). Ver `docs/upgrading.md`
- `POST /webhooks/mail` (Resend) pasa al mecanismo de webhooks entrantes genérico: la URL no cambia, pero ahora responde 200 tras guardar el recibo y procesa en cola (`ResendMailEventsHandler`); `MailWebhookController` desaparece; las respuestas de error llevan `code` (`webhook_signature_invalid`...). `SvixSignatureVerifier` implementa también `InspectsWebhookSignatureInterface`
- `Tenant`: `is_active` pasa a `status` (string), desaparecen `Tenant::setCurrent/resolveCurrent/clearCurrent/hasCurrent` (usa `TenantContext`), `Tenant::users()` es many-to-many vía `tenant_user`; `ResolveTenant` se reescribe (alias `tenant`)

### Added
- Correo (ola 5.2-B, `docs/mail-system.md`): lista de supresión global `mail_suppressions` alimentada por rebotes permanentes y quejas de Resend (`ResendMailEventsHandler`), aplicada al enviar (destinatarios descartados quedan `skipped` en `mail_logs`; los críticos como `password_reset` ignoran los rebotes, configurable), `GET/POST/DELETE /admin/mail/suppressions` con permiso nuevo `mail.suppressions`, dominios reservados (`example.com`, `.test`, `.invalid`, `.localhost`, `.example`) que nunca reciben correo, y marca por tokens (`config/brand.php`, ajustes `brand.*` por tenant/plataforma, `BrandResolverInterface`) en el layout de Markdown mail. Migración `2026_11_20_000220`
- Notificaciones multicanal (ola 5.2-A, `docs/notifications.md`): tipos declarados en `config/notifications.php` con textos en `lang/`; `NotificationServiceInterface::notify()` (en cola, `afterCommit`) con un único camino (`TypedNotification` + canal `delivery`) hacia `mail`, bandeja in-app (`database`), `push` y `sms`; preferencias por tipo/canal y horas de silencio en la zona horaria del usuario (los tipos `mandatory` las ignoran); entregas con reclamo idempotente (`notification_deliveries`), barrido (`notifications:sweep`) y retención (`notifications:purge`); push con `PushTransportInterface` real/simulado (`PUSH_MODE`, FCM HTTP v1 sin SDK). Endpoints `/user/notifications*`, `/user/notifications/preferences`, `/user/devices`. Migraciones `2026_11_20_000200`-`000204`. Elimina `NotificationHistory` y los métodos stub del servicio viejo (ver `docs/upgrading.md` 15d)
- Auditoría de negocio (ola 5.2-D, `docs/audit.md`): tabla `audit_logs` (migración `2026_11_20_000260`, inmutable, `jsonb`), trait `Auditable` opt-in con diff antes/después sin secretos (`Redactor`), actor usuario/API key/sistema, tenant, IP y `request_id`; aplicado a `Tenant`, `TenantInvitation`, `Webhook`, `ApiKey` y a los miembros vía servicio. `AuditServiceInterface` con acciones sensibles con motivo obligatorio (`audit_reason_required`; `audit.reason_required`), `GET /tenant/audit` y `/tenant/audit/export` (permiso `tenant-audit.read`, CSV en streaming), `GET /admin/audit` y `/export` (`audit.read`), retención `audit:prune` programada. `ForceJsonResponse` ya no pisa el `Content-Type` de las descargas (`Content-Disposition: attachment`)
- Archivos por empresa, subida directa y cuotas (ola 5.2-C, `docs/files.md`): prefijo de entorno en las claves de disco (`FILES_PREFIX`, por defecto `APP_ENV`); subida directa presignada (`POST /files/uploads` reserva cuota y devuelve la URL PUT; `POST /files/uploads/{id}/complete` verifica existencia, tamaño, tipo por contenido y checksum antes de crear el `File`; tabla `file_upload_intents`, migración `2026_11_20_000240`; comando `files:purge-intents` programado cada hora); cuotas por plan `max_storage_mb` (403 `storage_quota_exceeded`) y nuevo límite `max_file_mb` (413 `file_too_large_for_plan`), con bloqueo del tenant (`PlanLimits::guarded` ahora acepta `unit`) y prueba de concurrencia con procesos reales; `GET /files/usage`; `storage:check` informa de la subida directa. Códigos: `direct_upload_unsupported`, `upload_object_missing`, `upload_size_mismatch`, `upload_mime_mismatch`, `upload_checksum_mismatch`, `upload_intent_expired`, `upload_intent_aborted`
- Sesiones, 2FA y verificación de correo (ola 5.3-A, `docs/sessions-and-2fa.md`): tabla `user_sessions` (migración `2026_11_20_000280`), claim `sid` en los JWT y rechazo de sesiones revocadas en `ApiJwtGuard` (cacheado), `GET /user/sessions`, `DELETE /user/sessions/{id}` y `DELETE /user/sessions`; refresh rotado con compare-and-swap en BD y detección de reutilización (revoca la sesión; el refresh inmediatamente anterior dentro de `AUTH_REFRESH_REUSE_GRACE` da 401 `refresh_superseded` sin revocar); 2FA TOTP opcional para la API (`/user/2fa*`, `POST /auth/2fa/verify`, desafío opaco de un solo uso, códigos de recuperación hasheados, límite de intentos; reutiliza las columnas cifradas del back-office); `POST /auth/email/verify` y `POST /auth/email/resend` (token HMAC con caducidad, `VerifyEmailNotification`), middleware `verified` (403 `email_not_verified`); cambiar el correo anula `email_verified_at` y reenvía la verificación; middleware de versión mínima de app (`X-App-Version`/`X-App-Platform`, 426 `app_update_required`, `config/app-versions.php`). Códigos nuevos: `two_factor_invalid`, `email_not_verified`, `app_update_required`
- Exportar e importar (ola 5.3-C, `docs/export-import.md`): exportación CSV/XLSX en streaming (`openspout/openspout`, `lazyById`, neutraliza inyección de fórmulas) a partir de exportadores declarativos (`App\Exports\Exporter`, `config/export.php`), con job en cola, archivo en el disco privado con prefijo por tenant y URL temporal; importación con vista previa (validar filas con reglas de Laravel, confirmar una sola vez, proceso por lotes idempotente, informe de errores descargable), por tenant; ejemplos: `POST /admin/exports/users`, `POST /tenant/exports/members` (`tenant-members.export`), `POST /admin/imports/users` (`users.import`). Permisos `users.export`, `users.import`, `tenant-members.export`. Tablas `data_exports` y `data_imports` (migraciones `2026_11_20_000300` y `000301`), comando `exports:prune` cada hora (también marca como `failed` lo atascado en `processing`); avisos `export_ready` e `import_finished` por el sistema de notificaciones. Un único servicio PDF (`PdfRendererInterface`, dompdf, sin binarios externos). Códigos nuevos: `exporter_unknown`, `importer_unknown`, `tenant_required`, `export_not_ready`, `file_missing`, `import_not_confirmable`, `import_nothing_to_import`, `import_file_invalid`
- Idempotencia HTTP (ola 5.1-A, `docs/idempotency.md`): middleware `idempotent` / `idempotent:required` sobre la cabecera `Idempotency-Key` (tabla `idempotency_keys`, reserva atómica por tenant+actor+clave, repetición con `Idempotent-Replayed: true`, 422 `idempotency_key_mismatch`, 409 `idempotency_key_in_progress`, 400 `idempotency_key_required`; 5xx libera la clave; cuerpo guardado cifrado), `IdempotencyInterface`, `config/idempotency.php`, comando `idempotency:clean` programado cada hora, aplicado en modo opcional a `POST /webhooks` y `POST /tenant/invitations`. Migración `2026_11_20_000100`
- Integraciones externas y webhooks entrantes (olas 5.1-B y 5.1-C, `docs/integrations.md`): patrón real/simulada genérico (`app/Integrations/`, `config/integrations.php`, `IntegrationsServiceProvider::bindByMode()` por `<SERVICIO>_MODE`, errores tipados `Transient/RateLimited/QuotaExceeded/Permanent/InvalidOutput`, `RetryPolicy` que solo reintenta lo reintenciable con backoff + jitter y `retry_after`, `IntegrationHttp` con timeouts, trait `RecordsCalls` para fakes con llamadas registradas y `failNext()`), con SMS como ejemplo completo (`SmsSenderInterface`, `FakeSmsSender`/`LogSmsSender`/`HttpSmsSender`, canal `sms` de Notifications, `SendSmsJob`; `NotificationService::sendSms` ya envía de verdad). Webhooks entrantes genéricos: `POST /webhooks/inbound/{provider}`, tabla `inbound_webhook_receipts` (migración `2026_11_20_000120`, idempotente por `(provider, delivery_id)`), verificadores `svix` y `hmac` (ventana anti-replay, tiempo constante), tamaño máximo, rate limit, `ProcessInboundWebhookJob` con reintentos y handler por proveedor, resolución opcional de tenant, comando `webhooks:inbound-sweep`. Códigos nuevos: `webhook_signature_missing|invalid`, `webhook_timestamp_expired`, `webhook_provider_unknown`, `webhook_not_configured`, `webhook_payload_malformed`
- Suite de aislamiento multiempresa (ola 4.3-A): `tests/Feature/Tenancy/Aislamiento/` (archivos, webhooks y entregas, API keys, invitaciones, miembros, ajustes y locale del tenant, overrides de flags, plan y límites, numeraciones, logs, jobs y caché), plantilla `tests/Support/AssertsTenantIsolation.php`, meta-test `CoberturaDeAislamientoTest` que exige prueba para todo modelo con `BelongsToTenant` o tabla con `tenant_id`, y guía en `docs/multi-tenancy.md`
- Numeraciones atómicas: `App\Contracts\SequenceInterface` / `App\Support\Sequences\Sequence` (bloqueo de fila, por tenant, prefijo/relleno/`{year}`, reinicio anual, máximo con `SequenceExhausted`, `peek()`), tabla `sequences` (migración `2026_11_19_000070`), `config/sequences.php`, pruebas con procesos reales en la suite Concurrency y `docs/sequences.md`
- Dinero: `App\Support\Money\Money` (inmutable, entero en unidad menor + ISO 4217; `add`/`subtract`/`multiply`/`percentage` con `Rounding` explícito, `allocate`/`split` sin perder unidades, `fromDecimalString`, `format` por locale), `config/currencies.php`, `App\Casts\MoneyCast` (`*_minor` + `currency`), regla `App\Rules\MoneyAmount`, test de arquitectura que prohíbe float/double/decimal en columnas amount/price/total/balance de migraciones; `docs/money.md` pasa de norma futura a implementado
- i18n (ola 4.2-D, `docs/i18n.md`): idioma, zona horaria y moneda con herencia usuario -> tenant -> `Accept-Language` -> instalación. `config/i18n.php`, `LocaleResolverInterface`, middleware global `SetApiLocale` (restaura el locale al terminar, seguro con Octane; responde `Content-Language`), `lang/es` y `lang/en` (validación, auth, passwords, mensajes y correos) con test de paridad de claves, `User implements HasLocalePreference` (correos en cola en el idioma del destinatario), `GET/PUT /user/locale`, `GET/PUT /tenant/locale`, permiso `tenant-settings.manage`. Migración `2026_11_19_000140` (`users.locale`; `tenants.locale/timezone/currency`; `users.timezone` sin default)
- Multiempresa, onboarding (ola 4.2-A, `docs/tenant-onboarding.md`): alta de empresa (`POST /tenants`, `GET /tenants/mine`; opcional, con revisión `pending` + `tenancy:approve` y `trial_ends_at`), invitaciones (`/tenant/invitations`: crear, listar, reenviar con rotación de token, revocar y aceptar; token solo como hash sha256; correo en cola) y miembros del tenant (`/tenant/members`: listar, cambiar rol, suspender, reactivar, quitar; jerarquía de roles y salvaguarda del último dueño). `tenant_user.role`, tabla `tenant_invitations`, `TenantStatus::Pending`, middleware `tenancy.enabled` (404 con multiempresa apagado), permisos `tenant-members.read`, `tenant-members.manage`, `tenant-invitations.manage` y roles `tenant-owner/admin/member`. Comando `tenancy:set-role`. Migraciones `2026_11_19_000100` y `000101`
- Ajustes por niveles, feature flags en BD y límites por plan (ola 4.2-B, `docs/settings-and-flags.md`): `SettingsRegistryInterface` (`config/settings.php`, tenant -> plataforma -> default, tipos bool/int/string/enum/json, endpoints `/settings/registry` y `/tenant/settings`), `FeatureFlagServiceInterface` (flags con override por tenant/usuario y porcentaje estable por hash, endpoints `/admin/feature-flags` y `/tenant/features`), `PlanLimitsInterface` (`config/plans.php`, `guarded()` con bloqueo de la fila del tenant, `max_api_keys` aplicado, endpoints `/admin/tenants/{id}/plan` y `/tenant/limits`). Permisos nuevos `tenant-settings.read|manage`, `feature-flags.read|manage`, `plan-limits.manage`. Migraciones `2026_11_19_000120` y `000121`
- Contrato OpenAPI 3.1: `make openapi` / `php artisan openapi:export` genera `docs/openapi.json` (versionado, formato estable; `--check` y un test de la suite por defecto fallan si está desactualizado). Scramble documenta ahora el envelope de éxito y de listados, los errores con `ApiError` (`code` + `request_id`), la seguridad Bearer JWT / API Key por operación y `page`/`per_page`, mediante extensiones (`app/OpenApi`) y no anotaciones por controller. Quedan fuera del documento backoffice, Livewire, Horizon, Telescope y rutas de diagnóstico
- Suite `Contract` real: `assertMatchesOpenApi($response)` valida respuestas reales (health, auth, usuarios, settings, webhooks, 401/403/404/422) contra `docs/openapi.json` con `opis/json-schema` (dev)
- `make types`: tipos TypeScript para los fronts con `openapi-typescript` (`docs/api-types.d.ts`, no versionado); `docs/api-contract.md` con el flujo, el consumo desde Next.js/Expo y la paginación
- `RespondsWithApi::sendCursorPaginated()`: paginación por cursor opt-in (sin cambiar endpoints existentes)
- Errores con `code` estable y `request_id`: enum `App\Enums\ErrorCode`, `ApiException::errorCode()` para códigos de proyecto, `code`/`request_id` en todas las respuestas de error (handler RFC 7807, `ApiResponse`, `sendError`, validación, 429, JWT, middlewares) y cabecera `X-Request-ID` en todas las respuestas (se respeta la entrante si es válida). Cambio aditivo: no cambia status ni quita campos. `TraceIdMiddleware` pasa a ser global (cubre también 404/405 sin ruta)
- Arranque de proyecto nuevo: `make init NAME=<slug>` (compose.env y .env con puertos, contraseñas y claves propios; renombra composer/package; `origin` -> `upstream`), `make setup`, `make doctor`, `make fresh`, `make upgrade-check`, `make wt-env` (entorno por worktree) y `docs/new-project.md`. `make validate` ahora comprueba puertos repetidos, `REDIS_PASSWORD` vacío y puertos de BD/Redis/S3/Mailpit abiertos al exterior
- `APP_TITLE` / `config('app.title')`: nombre visible de la API (ficha de `/` y título de `/docs/api`)
- Perfil `dev` de Compose con Mailpit y un emulador S3 (RustFS; MinIO ya no publica imágenes) con buckets público y privado creados solos; targets `up-dev`, `mail-ui`, `mail-clear`, `s3-ui`, `s3-init`, `s3-test`; suite `Integration` (`make test-integration`); `docs/local-services.md` (sustituye a `docs/minio-configuration.md`)
- `php artisan storage:check`: prueba los discos de archivos configurados (sustituye a `test:s3` y `minio:test`)
- CHANGELOG.md con formato Keep a Changelog
- CONTRIBUTING.md con guías de contribución
- ARCHITECTURE.md con documentación de arquitectura
- Multiempresa opt-in (`MULTI_TENANCY_ENABLED`, `docs/multi-tenancy.md`): `TenantContext` (scoped), `BelongsToTenant` fail-closed, middleware `tenant` (cabecera/ruta/subdominio/dominio, membresía activa o `tenants.access-any`), tabla `tenant_user`, `tenants.status`/`parent_id`, propagación a jobs con `Context`, claves de caché por tenant y `TenantQuery::withoutTenant()` como único escape. Migraciones `2026_11_19_000050` y `000051`, permiso `tenants.access-any`
- Multiempresa, recursos por tenant (ola 4.1-B): `files`, `webhooks`, `webhook_deliveries` y `api_keys` con `BelongsToTenant` y rutas con `tenant`; ruta de almacenamiento `tenants/<id>/...` y URL firmada con el tenant dentro de la firma; `SendWebhookJob` y `WebhookListener` respetan el tenant; la API key fija el tenant al autenticar; los logs registran `tenant_id` (`RecordsTenant`, filtro `?tenant_id=`). Equipos de Spatie (`PERMISSION_TEAMS`): `PermissionTeamsSchema`, `tenancy:enable-teams`, equipo 0 = plataforma, `roles.tenant_id` NULL = rol global, `User::isPlatformAdmin()/canEnterTenant()`, `Role::visibleInScope()`. Comando `tenancy:assign-orphans`. Migraciones `2026_11_19_000060`, `000061`, `000062`
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
- `config/scramble.php`: `api_path` vacío (con `null` Scramble recortaba `api` y publicaba `/api-keys` como `/-keys`); `/health*`, `/docs/*` del asistente y las rutas con `\` inicial en su FQCN ahora sí salen en el documento
- `per_page` de los listados de correo, LLM, docs, escaneos y lista negra se acota a 1..100 (antes sin tope) y el de roles/permisos también (100 y 500)
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

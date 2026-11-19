# Arquitectura — APYGG

Arquitectura del boilerplate API con Laravel 13. Este documento describe lo que hay en el código. Lo pendiente está en [TASKS.md](./TASKS.md).

## Pipeline de una petición

Los middleware del grupo `api` se registran en `bootstrap/app.php`, en este orden:

1. `ResolveClientIpMiddleware`: IP real del cliente (detrás de Cloudflare).
2. `CorsMiddleware`: CORS con `ALLOWED_ORIGINS`.
3. `TraceIdMiddleware`: trace ID de la petición.
4. `SecurityHeadersMiddleware`: headers de seguridad HTTP.
5. `AdaptiveRateLimitingMiddleware`: rate limiting según el tipo de endpoint.
6. `ForceJsonResponse`: respuestas siempre JSON.
7. `CompressionMiddleware`: compresión (cuando no la hace Caddy).
8. `LogApiRequests`, `SecurityLoggerMiddleware`, `RateLimitLoggerMiddleware`: registro de peticiones, eventos de seguridad y bloqueos.

La entrada no se sanea ni se convierte de tipo en un middleware propio. Laravel ya aplica de forma global `TrimStrings` (recorta espacios, salvo `password`, `password_confirmation` y `current_password`) y `ConvertEmptyStringsToNull`. Regla: **la API guarda lo que recibe, validado con Form Requests; quien pinta HTML escapa** (Blade, plantillas de correo, el frontend). No se quitan etiquetas ni se convierten `"1"`/`"no"` en booleanos: si un campo es booleano, su Form Request lo declara (`boolean`) y lo castea el modelo. Las respuestas las forman `sendSuccess`/`sendError`/`sendPaginated` y los API Resources.

Después, cada ruta aplica sus propios middleware (`auth:api`, `auth:api-key`, `permission:`, `role:`, `role_or_permission:`, etc.). Las excepciones se renderizan siempre como JSON desde `bootstrap/app.php`, y Sentry las captura.

## Capas

```
Petición → Middleware → Controller → Form Request → Service → Model
                                         ↓
                                     Resource → Respuesta
```

- **Controllers**: una sola base, `Controller` (abstracta, sin CRUD genérico). Cada controller declara sus acciones con Form Requests tipados. Las respuestas usan `sendSuccess`, `sendError` y `sendPaginated`.
- **Form Requests**: heredan de `BaseFormRequest`.
- **Resources**: heredan de `BaseResource`.
- **Services** (`app/Services`): lógica de aplicación. Se inyectan por interfaz en `app/Contracts`, con los bindings en `ContractServiceProvider`.
- **Sin repositorios ni DTOs**: el servicio usa Eloquent directamente y recibe arrays validados por el Form Request. Solo se crearía un repositorio para consultas complejas compartidas por varios servicios.
- **Models** (`app/Models`): heredan de `App\Models\Model` (`HasFactory` y `SoftDeletes`). La búsqueda con Scout usa el trait `Searchable`, y el UUID es opcional con el trait `HasUuid`.

## Módulos

| Módulo | Código principal | Rutas |
|---|---|---|
| Auth JWT | `AuthService`, `TokenService`, `AuthController`, `PasswordController` | `/auth/*`, `/auth/me`, `/auth/change-password` |
| API Keys | `ApiKeyService`, guard `api-key`, `CheckApiKeyScope` | `/api-keys` |
| RBAC | spatie/laravel-permission: modelos `Role` y `Permission` (extienden los del paquete), `PermissionService`, `RoleService`, policies, middlewares `permission:`/`role:` del paquete | `/roles`, `/permissions` |
| Usuarios y perfil | `UserService`, `UserProfile`, `Users\UserController` | `/users`, `/user/profile`, `/user/preferences` |
| Logging | `LogService`, `app/Services/Logging/*`, `app/Logging/` | `/admin/logs/*` |
| Seguridad | `SecurityAuditService`, `SecurityService`, `Blacklist`, `SecurityScan` | `/admin/blacklist`, `/admin/security/scans` |
| Webhooks salientes | `WebhookService`, `SendWebhookJob`, `WebhookDelivery` | `/webhooks/*` |
| Correo | Mailables/Notifications estándar, `AccountsTransport`, `MailSystemListener`, `app/Services/Mail/*`, `app/Models/Mail/*` | `/admin/mail/*`, `/webhooks/mail` |
| Gateway LLM | `app/Services/LLM` (`LlmGateway`, `LlmAgent`, `LlmAuditService`, proveedores OpenAI, DeepSeek y Null), `LlmArea` | `/admin/llm/*` |
| Asistente RAG | `app/Services/Docs` (`DocsIndexer`, `DocsService`), `DocChunk`, `DocConversation` | `/docs/*` |
| Menú dinámico | `MenuService`, `MenuItem` | `/admin/menu/*` |
| Archivos | `FileService`, discos de `config/filesystems.php` | `/files/*` |
| Búsqueda | Scout con Meilisearch, `SearchController`, trait `Searchable` | `/search` |
| WebSockets | Reverb, `BroadcastAuthController`, `routes/channels.php` | `/broadcasting/auth` |
| Caché | `CacheService`, comandos `cache:*` | — |
| Backups | `BackupService`, comandos `backup:*` y `db:backup` | — |
| Feature flags | `config/features.php`, helper `App\Helpers\Feature`, middleware `CheckFeatureFlag` | — |
| Back-office (opcional) | Filament 5: `app/Filament/*`, `BackofficePanelProvider`, `config/backoffice.php`; reutiliza servicios y policies de la API | `/backoffice` (web, sesión + MFA) |
| Health | `HealthController` | `/health`, `/health/live`, `/health/ready`, `/health/detailed` |
| Documentación API | Scramble (`config/scramble.php`) | `/docs/api` |

Fuera del boilerplate a propósito: login social (Socialite), notificaciones push (FCM) y suscripciones/cobros. Son decisiones de cada proyecto.

## Flujos

### Autenticación

`AuthController` valida con un Form Request y delega en `AuthService`. `AuthService` usa `TokenService` para emitir y renovar el JWT, y consulta al usuario con Eloquent. Los eventos de login/logout de Laravel (`Login`/`Logout`) los escucha `LogAuthenticationEvents`, que deja un único registro de seguridad por suceso; `UserLoggedIn`/`UserLoggedOut` son eventos de dominio solo para webhooks. Las API Keys entran por `auth:api-key` y se validan con `api-key-scope:`.

### Webhooks salientes

`WebhookService` crea la entrega (`WebhookDelivery`) y despacha `SendWebhookJob` a la cola. Los reintentos se lanzan desde `/webhooks/{id}/deliveries/{deliveryId}/retry`.

### Correo

Todo correo es un Mailable o Notification de Laravel; un transport propio (`accounts`) y listeners de `MessageSending`/`MessageSent` aplican cuenta, pausa, límites y registro (ver `docs/mail-system.md`). Los eventos de entrega del proveedor llegan a `/webhooks/mail` y se verifican con `SvixSignatureVerifier`.

### Gateway LLM

Los casos de uso llaman a `LlmGateway`, que elige el modelo según el área configurada en BD (`LlmArea`), usa el proveedor y los modelos de respaldo, y registra la llamada en `LlmAuditService`.

### Asistente RAG

`DocsIndexer` (comando `docs:index`) trocea los Markdown en `DocChunk`. La búsqueda usa `tsvector` de PostgreSQL. Según la migración `create_docs_tables`, no se usa pgvector.

## Cómo agregar un módulo

1. **Migración y modelo**: `app/Models/<Modelo>.php` que herede de `App\Models\Model`.
2. **Servicio**: `app/Services/<Modulo>Service.php`. Si hace falta, interfaz en `app/Contracts/` y binding en `ContractServiceProvider`.
3. **Form Requests** en `app/Http/Requests/<Modulo>/`, heredando de `BaseFormRequest`.
4. **Resource** en `app/Http/Resources/<Modulo>/`, heredando de `BaseResource`.
5. **Controller**: en `app/Http/Controllers/<Modulo>/`. Extiende `Controller` y declara tus acciones con Form Requests tipados (nunca un parámetro sin tipo).
6. **Rutas**: `routes/api/<modulo>.php`, incluido con `require` desde `routes/api.php`. Sin prefijo `/api`.
7. **Permisos**: protege las rutas con `auth:api` y `permission:<recurso.accion>,api` (ver `docs/permissions.md`).
8. **Tests**: Pest, en `tests/Unit/` o `tests/Feature/`, con nombres en español que describan la conducta. Cubre también el caso denegado.
9. **Calidad**: `make check` (Pint y PHPStan) y `make test-filter filter=<Nombre>`.

## Configuración

Cada área tiene su archivo en `config/` (por ejemplo `auth.php`, `jwt.php`, `cache.php`, `cors.php`, `database.php`, `filesystems.php`, `horizon.php`, `reverb.php`, `scout.php`, `scramble.php`, `security.php`, `rate-limiting.php`, `features.php`, `backups.php`, `logging.php`, `llm.php`, `docs-assistant.php`, `mail.php`, `mail-system.php`, `multi-tenancy.php`, `webhooks.php`). Los valores sensibles van en `.env`, no en el código.

## Decisiones

- **Sin repositorios ni DTOs** (decisión: lo estándar en Laravel es Eloquent en el servicio).
- **Base de datos**: PostgreSQL exclusivamente (`jsonb`, `tsvector`). Columnas JSON siempre `jsonb`; el ID por defecto es entero y `HasUuid` es opcional.
- **Rutas sin `/api`**: el prefijo se omite a propósito. Ver la tabla de prefijos en [README.md](./README.md#rutas).

# TASKS — Estado real del boilerplate APYGG

Revisado contra el código el 2026-11-19. Lo marcado como hecho está comprobado en el código o en las rutas.

## Hecho

- **Auth JWT**: login, registro, refresh, recuperación y reset de contraseña, logout, `me` y cambio de contraseña (`routes/api/auth.php`).
- **API Keys**: alta, listado, revocación y rotación; scopes con `api-key-scope:` (`routes/api/api-keys.php`).
- **RBAC propio**: modelos `Role` y `Permission`, tablas pivote, middleware `permission:` y `role:`. Spatie está en `composer.json` pero no se usa.
- **Usuarios y perfil**: CRUD, restauración, roles por usuario, actividad; perfil y preferencias en `/user`.
- **Roles y permisos**: CRUD de roles, permisos agrupados y asignación de permisos a roles.
- **Webhooks salientes**: CRUD, entregas, reintentos y rotación de secreto (`SendWebhookJob`).
- **Webhook de correo entrante**: `/webhooks/mail` con verificación de firma Svix.
- **Archivos**: subida, listado, descarga, actualización y borrado (`FileService`, discos `local`, `public` y `s3`).
- **Settings**: CRUD y consulta por clave (`routes/api/settings.php`).
- **Notificaciones push**: `FcmService` y `DeviceToken`.
- **Correo**: multi-cuenta y multi-proveedor, plantillas, registro de envíos y pausa global (`/admin/mail`).
- **Gateway LLM**: áreas configurables en BD, respaldos y auditoría (`/admin/llm`).
- **Asistente RAG**: indexado de Markdown (`docs:index`) y búsqueda y preguntas (`/docs/*`).
- **Menú dinámico**: CRUD y visibilidad (`/admin/menu`).
- **Seguridad**: auditoría con escaneos (`/admin/security/scans`), lista de bloqueo (`/admin/blacklist`), headers, rate limiting adaptativo y registro de eventos.
- **Logging**: `LogService`, canales `activity`, `security`, `auth`, `sentry`, y registro de peticiones API.
- **Health**: `/health`, `/health/live`, `/health/ready` y `/health/detailed`.
- **Documentación de API**: Scramble en `/docs/api`.
- **Caché**: `CacheService` y comandos `cache:list`, `cache:invalidate`, `cache:metrics`, `cache:warm`.
- **Backups de BD**: crear, listar, limpiar, restaurar y restaurar desde remoto (comandos `backup:*`, `db:backup`).
- **Búsqueda**: Scout con Meilisearch (`/search`) y sincronización de índices.
- **Infraestructura**: Docker Compose con `app`, `reverb`, `horizon`, `scheduler`, `postgres` y `redis`; `pgbouncer` (perfil `prod`) y `meilisearch` (perfil `search`) como opcionales.
- **CI**: job de lint (Pint y PHPStan) y job de tests (Pest con cobertura), `composer audit`, `cd.yml`, SonarCloud y Dependabot.
- **Tests**: 286 pruebas Pest en verde (Unit, Feature y Arch), `pint` y `phpstan` (nivel 5) limpios.
- **Makefile**: partido en `make/*.mk` por tema.

## Pendiente

### Crítico

- [ ] **Multi-tenancy a medias.** Existen el modelo `Tenant`, la migración `create_tenants_table`, el trait `BelongsToTenant`, `TenantScope` y el middleware `ResolveTenant`. El middleware no está registrado en `bootstrap/app.php`, ninguna tabla tiene columna `tenant_id` y `config/multi-tenancy.php` viene con `enabled = false`. Decidir si se completa o se retira.
- [ ] **Login social sin rutas.** `SocialAuthController` y `SocialAuthService` existen, pero no hay rutas, `config/services.php` no tiene Google ni GitHub, y no hay migración para `social_accounts`.
- [ ] **Backup de archivos.** `BackupCreateCommand` lo marca como TODO y solo avisa de que no está implementado.
- [ ] **Sin suscripciones ni cobros.** No hay pasarela de pagos, ni `Cashier` ni modelos de suscripción.

### Importante

- [ ] **Repositorios sin usar.** Existen siete repositorios con sus interfaces, pero solo `AuthService` y `UserService` los usan. El resto de servicios accede a Eloquent directamente.
- [ ] **DTOs parciales.** Hay seis DTOs. Los servicios que no los reciben siguen con arrays.
- [ ] **Feature flags sin uso.** `config/features.php` define `advanced_search`, `export_users` y `two_factor_auth`, pero ningún código los consulta. El middleware `feature:` no se usa en ninguna ruta.
- [ ] **Dependencias sin uso.** `setasign/fpdf`, `setasign/fpdi`, `barryvdh/laravel-snappy` y `spatie/laravel-query-builder` no aparecen en el código. Decidir si se quitan.
- [ ] **Cobertura.** El objetivo del 80% en código crítico no está medido.

### Menor

- [ ] `AGENTS.md` describe Sanctum como método alternativo, pero Sanctum no está en `composer.json`. Corregir `AGENTS.md` o instalar el paquete (`AGENTS.md` no se tocó en esta revisión).

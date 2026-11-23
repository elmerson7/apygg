# Actualización para proyectos derivados

Qué rompe o exige acción al pasar un proyecto creado desde APYGG a una versión
más reciente. Cada sección empieza por la versión de origen. Los cambios sin
impacto en un proyecto existente están en `CHANGELOG.md`.

> **Nombres de migración históricos.** Antes de v1.0.0 las migraciones del boilerplate se consolidaron en
> creaciones limpias (`docs/migrations.md`): los nombres `2026_11_19_*`/`2026_11_20_*` de las secciones de abajo
> describen el historial y ya no existen como archivo. Ningún proyecto derivado existía entonces; a partir de
> v1.0.0 las migraciones son inmutables y los cambios se anotan aquí.

> Cómo traer los cambios del boilerplate a tu proyecto (remoto `upstream`, merge, conflictos): `docs/upgrading-from-boilerplate.md`.

> Antes de empezar: **backup de la BD** (`make backup`) y una rama aparte.
> Aplica los pasos en este orden. Los comandos son los de Make del repo
> (nunca `php artisan` en el host).

## De 1.0.0 a la versión actual (Unreleased)

### Multiempresa: builder con escrituras masivas aisladas y coherencia de la bandera

- Los modelos con `BelongsToTenant` usan ahora `TenantEloquentBuilder`: con multiempresa activo, `insert`/`upsert`/
  `insertOrIgnore`/`insertGetId` rellenan `tenant_id` (o lanzan `CrossTenantViolation` si la fila trae otro) y
  `update()` con `tenant_id` lanza. Si tu código insertaba filas masivas de un modelo con tenant sin contexto (comandos,
  seeders), envuélvelo en `TenantContext::run($tenant, ...)` o pasa `tenant_id` dentro de `TenantQuery::withoutTenant()`.
  Si un modelo tuyo ya define `newEloquentBuilder`, extiende `TenantEloquentBuilder`.
- Nuevo `php artisan tenancy:check` y `MULTI_TENANCY_STRICT` (por defecto true solo en producción): con
  `MULTI_TENANCY_ENABLED=false` y más de una empresa en `tenants`, el middleware `tenant` responde 500. Si tu despliegue
  estaba en ese estado, activa la multiempresa o limpia las empresas sobrantes.
- Nueva regla de `ArchTest`: `DB::table()` sobre tablas con `tenant_id` solo en los archivos auditados; añade los tuyos a la lista tras revisarlos.

### Back-office: datos personales exportados piden `users.read`

- El recurso «Datos personales exportados» (módulo privacy) pedía `users.export`, que es del módulo export-import: al quitar ese módulo el panel fallaba. Ahora pide `users.read` (núcleo). Si diste `users.export` a soporte solo para ver ese recurso, dale `users.read`.

### `trace_id` en la auditoría de actividad y límites de `/auth/refresh` y cambio de contraseña

- **Migraciones nuevas** (`make migrate`):
  - `logs_activity.trace_id` (varchar 64, nulo, índice `CONCURRENTLY`): une cada cambio con su petición.
  - `logs_api.trace_id` y `logs_security.trace_id` pasan de `uuid` a `varchar(64)`. Antes un `X-Request-ID` del
    cliente que no fuera UUID (válido según el contrato) hacía fallar el insert del log. El cambio de tipo reescribe
    la tabla: si tus logs son muy grandes, purga antes (`php artisan logs:clean --days=30`).
- `LogService::logActivity()` ahora sí guarda `before`/`after` en `old_values`/`new_values` (antes se perdían).
- `/admin/logs/*` acepta `filter[trace_id]`.
- **Límites nuevos**: `RATE_LIMIT_REFRESH_MAX` (20/min por IP) para `/auth/refresh` y `RATE_LIMIT_SENSITIVE_MAX`
  (5/min por usuario) para `/auth/change-password`. Antes contaban como escritura genérica (30/min). Si tus apps
  renuevan el token muy a menudo desde una misma IP, sube `RATE_LIMIT_REFRESH_MAX`.
- `config/migration-lint.php`: `large_tables` usaba nombres que no existen (`api_logs`, `activity_logs`, `llm_logs`);
  ahora `logs_api`, `logs_activity`, `logs_security` y `logs_llm`.

### Puertos en 127.0.0.1, validación de arranque en producción y `.env.production.example`

- **`docker-compose.yml`**: `app`, `reverb`, `pgbouncer` y `meilisearch` publican en `127.0.0.1` (variable `BIND_HOST`, por
  defecto `127.0.0.1`). Si accedías a ellos desde otra máquina en desarrollo, pon `BIND_HOST=0.0.0.0` en `compose.env`.
  En producción (`deploy/compose.prod.yml`) no cambia nada.
- **`ProductionConfigGuard`**: con `APP_ENV=production` la app no arranca con `APP_DEBUG=true`, claves por defecto o Sentry al
  100 %. Revisa tu `.env` de producción contra `.env.production.example` antes de desplegar (`docs/deploy.md`).
- `webhooks:cleanup-old-secrets` queda programado a diario (`routes/console.php`; si tienes el schedule personalizado, añádelo).
- Hook `pre-commit`: usa el `PROJECT` de `compose.env` (contenedor `<PROJECT>-app`) y avisa si no hay contenedor; reinstala con `make install-hooks`.
- Se eliminan docs obsoletas (`docs/archive/PLAN_ACCION_2025.md`, `api-only-considerations.md`, `frankenphp-compression-test.md`,
  `features-migration-to-database.md`) y se añade `docs/README.md` (índice).
### Limpieza de código y seguridad (ola 9, `limpieza-codigo`)

- **Documento de identidad opcional.** `identity_document` deja de ser obligatorio y sin formato de país en el alta de
  usuarios (`POST /users`): `nullable`, único si viene. Tu regla (p. ej. DNI) va en `USERS_IDENTITY_DOCUMENT_REGEX`
  (`config/users.php`, regex con delimitadores; vacío = sin formato). La columna ya era `nullable` en la migración consolidada.
- **Canales públicos eliminados.** `notifications` y `test-messages` (`routes/channels.php`) ya no existen: cualquiera
  podía suscribirse sin autenticar. Si tu front los escuchaba, emite por `private-user.{id}` o `tenant.{id}` (`docs/websockets.md`).
  `POST /broadcasting/auth` ya no devuelve el texto de la excepción.
- **`ETagMiddleware` borrado** (no estaba registrado). Si lo usabas, recupéralo de git.
- **Nombre de la instalación fuera del código.** Nueva `app.slug` (`APP_SLUG`, por defecto el slug de `APP_NAME`). De ella salen
  el prefijo de las claves de `CacheService` (antes literal `apygg:`), los prefijos de las API keys (`<slug>_live_`/`<slug>_test_`,
  antes `apygg_live_`/`apygg_test_`), el User-Agent de los webhooks (`WEBHOOKS_USER_AGENT`, antes `APYGG-Webhook/1.0`) y el
  `format` del manifiesto de la exportación de datos personales. **Para no invalidar nada en un proyecto en marcha**, fija
  `APP_SLUG=apygg` (o `API_KEYS_PREFIX_LIVE=apygg_live_` y `API_KEYS_PREFIX_TEST=apygg_test_`): las API keys ya emitidas se validan por hash,
  así que siguen valiendo con cualquier prefijo, pero la caché propia se reconstruirá. Arreglado de paso: `ApiKeyObserver` y
  `WebhookObserver` invalidaban `apygg:apygg:...` (doble prefijo) y no borraban nada.
- **Lista negra de JWT en store propio.** Con `CACHE_STORE=redis`, `JWT_BLACKLIST_STORE` vale `jwt_blacklist` (BD Redis `REDIS_JWT_DB`, 3).
  **Al desplegar, los tokens revocados antes quedan fuera de la lista nueva** (siguen en la caché general, que ya no se consulta) y
  volverían a valer hasta caducar: revoca sesiones críticas (`User::revokeAllTokens()`) o fija `JWT_BLACKLIST_STORE=` (vacío) para
  seguir con la caché general. Ver `docs/sessions-and-2fa.md`.
- Nuevo middleware `deprecated:<sunset>[,<url>]` (cabeceras `Deprecation`/`Sunset`/`Link`; `docs/api-routes-conventions.md`).
- Los logs de refresh token ya no incluyen `token_preview` (50 caracteres del JWT) sino `token_fingerprint` (hash corto). Si
  tenías alertas sobre ese campo, cámbialas.
### Filtros, orden e include estándar (cambio menor de comportamiento)

- `GET /users`, `GET /files` y `GET /admin/logs/{activity,api,security}` aceptan `filter[campo]`, `sort=-campo,otro` e `include=` (aditivo; los parámetros de antes siguen valiendo).
- Un `sort`, `sort_by`, `include` o `filter[...]` **fuera de la lista blanca** ahora responde **400 `invalid_query`**; antes `sort`/`include` desconocidos caían en silencio al valor por defecto. Si un cliente enviaba uno inválido, corrígelo.
- `GET /users` ahora respeta `?page=` (antes el servicio fijaba la página 1).
- Se quita `spatie/laravel-query-builder` de `composer.json` (no se usaba). Si tu proyecto sí lo usaba, vuelve a requerirlo.
- Listados propios: declara `#[Listing]` y llama a `$this->listing()` (`docs/api-routes-conventions.md`).

### Prefijo de versión opcional `API_PREFIX` (no rompe: opt-in)

Con `API_PREFIX` sin definir (por defecto) todo funciona exactamente igual. Lo que aporta a un derivado:

- **`config/api.php`** nuevo y `App\Support\ApiPath`. `routes/api.php` agrupa las rutas de la app bajo `Route::prefix(ApiPath::prefix())`;
  si tu `routes/api.php` está personalizado, aplica lo mismo (las rutas de salud, `/` y `inbound-webhooks.php` quedan fuera del grupo).
- Los middlewares y servicios que comparaban `$request->path()`/`$request->is()` con patrones de config
  (`EnsureMinimumAppVersion`, `CheckMaintenanceMode`, rate limiting, `SubscriptionAccess`, consentimientos...) usan ahora
  `ApiPath::path()`/`ApiPath::is()`. Si añadiste middlewares propios con patrones de ruta, haz lo mismo para que valgan con prefijo.
- **Para activarlo** en un proyecto ya desplegado: solo si ninguna app publicada usa la API todavía. Si las hay, déjalo vacío
  y abre `/v2` como grupo aparte cuando haga falta (`docs/api-routes-conventions.md`).
- CI: nuevo job `test-prefix` (suite con `API_PREFIX=v1`) y `openapi-breaking` (oasdiff). Si tu CI es propio, añade
  `scripts/openapi-breaking.sh` y las variables `API_PREFIX` (E2E, `docker-compose.e2e.yml`), `LOAD_API_PREFIX` (k6) y
  `VITE_API_PREFIX` (frontend-demo).

### Módulos opcionales quitables (ola 8.2-A)

Sin cambios de comportamiento ni de rutas. Lo nuevo que afecta a un derivado:

- **Contratos nuevos** (núcleo): `SubscriptionPlanSourceInterface` (`PlanLimits` ya no lee la tabla `subscriptions`; el
  constructor de `PlanLimits` recibe el contrato) y `SecurityAnalysisProviderInterface` (`SecurityAuditService` ya no usa
  `LlmGateway`). Si tu proyecto hereda de esas clases o las construye con `new`, pasa el contrato (resuélvelo del contenedor).
- **`routes/api/maintenance.php`** nuevo: las rutas `/admin/maintenance` salen de `routes/api/impersonation.php`. Si tu
  `routes/api.php` está personalizado, añade `require __DIR__.'/api/maintenance.php';`.
- **Marcadores `// module:<nombre>`** en providers, rutas, seeders, `.env.example`, `docker-compose.yml`, `phpunit.xml` y tests
  compartidos: no cambian nada al ejecutar; son los que usa `make module-remove`. Si resuelves conflictos de merge, consérvalos.
- Para quitar un módulo en un derivado ya desplegado, usa `make module-remove name=X` (conserva las migraciones y crea una
  de baja); solo antes del primer despliegue admite `--purge-migrations`.

La versión 1.0.0 es la de `CHANGELOG.md` (2026-01-27). Todo lo de abajo sale de
`CHANGELOG.md`, `git log` y `.env.example`; no hay tag, así que si dudas de tu
punto de partida compara: `git diff <tu-commit-base> HEAD -- database/migrations .env.example`.

### 0. Antes de merge: lo que no se puede migrar solo

- **IDs de las tablas iniciales.** Tras 1.0.0, las migraciones originales
  (`2024_01_01_*`, `files`, `webhook_deliveries`) se editaron **en sitio**: los
  IDs pasaron de `uuid` a autoincremental (`id()`), y se añadieron
  `username`, `preferences` e `identity_document` a `users` (commit `a13b86f` y
  siguientes). No existe migración de conversión. Si tu BD de producción se creó con
  1.0.0 (IDs uuid): o conservas tus migraciones antiguas y adaptas el código a uuid
  (`HasUuid`), o migras los datos a una BD nueva. En un proyecto sin datos:
  `make art cmd="migrate:fresh --seed"`.
- **Sanctum no existe.** Nunca estuvo instalado; si lo usabas, es tuyo.

### Facturación y suscripciones (ola 6.1-A)

- Migraciones nuevas `2026_11_20_000400`-`000405` (`make art cmd="migrate"`): `plans`, `plan_prices`, `billing_accounts`, `subscriptions`, `invoices`, `invoice_lines`, `payments`. `Subscription`, `Invoice`, `Payment` y `BillingAccount` llevan `BelongsToTenant` (pruebas registradas en `CoberturaDeAislamientoTest`).
- Ejecuta `make art cmd="billing:sync-plans"` tras migrar y los seeders (`RoleSeeder` solo añade): permisos nuevos `tenant-billing.read|manage` (`tenant-owner`, `tenant-admin`) y `billing.manage` (plataforma).
- `PlanLimitsInterface` gana `refresh(int $tenantId)`; el plan efectivo sale de la suscripción con acceso y, si no hay, de `tenants.plan` (sin suscripciones nada cambia). Si implementas la interfaz, añádelo.
- `config/sequences.php` define ahora la secuencia `billing_invoice` (nombre propio: no comparte contador con una secuencia `invoice` de tu proyecto); `AUDIT_REASON_REQUIRED` por defecto incluye `billing.manual-payment,billing.free-period,billing.plan-assigned`.
- La numeración de facturas es **global** por defecto (`BILLING_INVOICE_SEQUENCE_SCOPE=global`). Los planes privados (`public: false`) ya no los contrata el tenant: los asigna la plataforma (`POST /admin/billing/tenants/{id}/plan`).
- **Ola 6.2-A (Stripe):** migraciones `2026_11_20_000460` (estado `requires_action`, reembolsos parciales, disputas, índices) y `000461` (`payment_discrepancies`); `make art cmd="migrate"`. Quien implemente su propia `PaymentGatewayInterface` debe añadir `findCharge`, `cancelCharge` y `recentCharges` (la `NullPaymentGateway` y la falsa ya los traen); `ChargeRequest` gana `customerPresent` y `ChargeResult` campos opcionales. Variables: `PAYMENTS_PROVIDER`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_VERSION`, `BILLING_REQUIRES_ACTION_HOURS`, `BILLING_RECONCILE_*`; `LOG_REDACT` gana el patrón `stripe` en `config/logging.php`. Registra en Stripe el endpoint `https://<api>/webhooks/inbound/stripe` con los eventos `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded` y `charge.dispute.created`. `payments:reconcile` ya está en `routes/console.php` (cada 10 min).
- Variables nuevas en `.env.example`: `PAYMENTS_MODE`, `BILLING_*` (incluye `BILLING_RENEW_INACTIVE_TENANTS`). Fija `BILLING_INVOICE_SEQUENCE_SCOPE` antes de la primera factura: después no se puede cambiar (409). Programa `billing:renew` (ya está en `routes/console.php`; cada 15 min).

### Cobros: concurrencia y riesgos de Stripe (ola 6.3-A)

- **Las claves de idempotencia hacia Stripe llevan ahora un prefijo por instalación** (`services.stripe.key_prefix`, `PAYMENTS_KEY_PREFIX`; por defecto `APP_ENV` + 8 hex de un hash de `APP_KEY`). Staging y producción con la misma cuenta de Stripe ya no comparten claves (`inv-7-a1`, `refund-5`, `customer-tenant-3`). **Fíjalo explícito en producción** (`PAYMENTS_KEY_PREFIX=acme-prod`) y no lo cambies con cobros en vuelo. La metadata `idempotency_key` y `payments.idempotency_key` siguen sin prefijo. Con cobros `pending` de antes del cambio, el reintento consulta primero por clave en Stripe (no reenvía a ciegas), así que no hay doble cobro; un `Idempotency-Key` ya usado con el prefijo viejo no se reutiliza.
- **`PaymentProcessor` recibe `PaymentDiscrepancyServiceInterface`** (constructor) y `PaymentOutcome` tiene un caso nuevo, `OtherIntent`: si tu proyecto hace `match` exhaustivo sobre `PaymentOutcome`, añádelo (se trata como `Pending`).
- La clave de reembolso es `refund-<pago>` si se devuelve todo el importe y `refund-<pago>-<importe>` si ya había una devolución parcial.
- `webhooks:inbound-sweep` también reencola los recibos `failed` (config `integrations.inbound_webhooks.sweep_failed`); el handler de tus webhooks entrantes debe ser idempotente (ya lo exigía `docs/integrations.md`).
- Resolver una discrepancia inexistente responde 404 `discrepancy_not_found` (antes `not_found`).

### Bloqueo por estado de la suscripcion (ola 6.2-B)

- Sin migraciones. `ResolveTenant` recibe `SubscriptionAccessInterface` (si lo construyes a mano, anadelo) y responde **402** a las escrituras de un tenant con suscripcion `past_due` fuera de gracia, `incomplete`, `canceled` o `expired` (`payment_required`, `subscription_inactive`); lectura y `billing.enforcement.allow_paths` siguen abiertas. Sin suscripcion no cambia nada (`BILLING_NO_SUBSCRIPTION=allow`). Apagalo con `BILLING_ENFORCEMENT_ENABLED=false`.
- `PlanLimitsInterface` gana `includesFeature()`; `FeatureFlagService` recibe `PlanLimitsInterface`. `TenantInvitationService` recibe `PlanLimitsInterface` y aplica `max_members` (con `PLAN_LIMITS_ENABLED=true` invitar/aceptar por encima del tope da 403 `plan_limit_reached`).
- `X-Subscription-Warning` se expone en CORS.

### Despliegue (ola 8.1-A)

- **`cd.yml` se reescribe**: desaparecen las estrategias cpanel, railway, blue-green, canary y rolling (nunca se
  probaron). Quedan en `docs/archive/despliegue-plantillas/` (también `scripts/deploy/{blue-green,canary,rollback}.sh` y
  `docs/cd-*.md`, sustituidos por `docs/deploy.md`). Si usabas alguna, copia la plantilla desde ahí a tu proyecto.
- Workflows nuevos: `release.yml` (publica `ghcr.io/<repo>:sha-<commit>` y `vX.Y.Z`) y el nuevo `cd.yml` manual (secrets
  `DEPLOY_HOST`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH`, `SMOKE_URL`). Make: `image`, `deploy`, `rollback`, `smoke`, `deploy-status`.
- `/health` y `/health/ready` añaden el campo `release` (`config('app.release')`, env `APP_RELEASE`, `dev` por defecto): un
  cliente estricto con el esquema debe aceptarlo. `docs/openapi.json` y los tipos se regeneran (`make openapi`).
- Nuevo `.env.deploy` (no versionado, plantilla `.env.deploy.example`) y `deploy/compose.prod.yml`: el compose de producción
  no incluye PostgreSQL ni Redis.

### Impersonación y modo mantenimiento (ola 5.3-B)

- Migración nueva `2026_11_20_000340_add_impersonator_to_audit_logs_table` (`make migrate`): `audit_logs.impersonator_id`.
  El CSV de auditoría gana una última columna `impersonator_id`.
- Permisos nuevos `users.impersonate` y `maintenance.manage` (vuelve a ejecutar `PermissionSeeder`; no se asignan a roles). Configs nuevas
  `config/impersonation.php` y `config/maintenance.php`; `impersonation.start` se añade a `audit.reason_required`.
- Dos middlewares nuevos al principio del grupo `api` en `bootstrap/app.php` (`CheckMaintenanceMode`, `EnforceImpersonation`).
  Añade a `impersonation.blocked_routes` las rutas sensibles de tu proyecto.

### Auditoría de negocio (ola 5.2-D)

- Migración nueva `2026_11_20_000260_create_audit_logs_table` (`make migrate`). Si tu proyecto tiene una tabla propia con
  `tenant_id`, nada cambia; `audit_logs` ya figura en `CoberturaDeAislamientoTest`.
- **Cambia el contrato de 4 rutas**: `POST /users/{id}/roles`, `DELETE /users/{id}/roles/{roleId}` y
  `DELETE /admin/blacklist/{id}` exigen ahora un campo `reason` (422 `audit_reason_required`). Para no exigirlo:
  `AUDIT_REASON_REQUIRED=` (vacío) o quita la acción de la lista en `config/audit.php`.
- Permisos nuevos `tenant-audit.read` (roles `tenant-owner` y `tenant-admin`) y `audit.read`: vuelve a ejecutar los
  seeders (`RoleSeeder` solo añade). `UserController` y `BlacklistController` reciben `AuditServiceInterface`;
  `TenantMemberService`, `TenantInvitationService` y `TenantOnboardingService` también (si los extiendes, ajusta el constructor).
- `ForceJsonResponse` deja intacto el `Content-Type` de las respuestas con `Content-Disposition: attachment`.

### Archivos: prefijo de entorno, subida directa y cuotas (ola 5.2-C)

- **Prefijo de entorno.** Las claves NUEVAS en disco pasan de `[tenants/<id>/]<ruta>` a `<FILES_PREFIX>/[tenants/<id>/]<ruta>`
  (`FILES_PREFIX` por defecto `APP_ENV`). Los archivos existentes **no se mueven ni se rompen**: su `path` completo está en BD
  y la descarga usa ese valor. Si quieres conservar las rutas de siempre para los nuevos, pon `FILES_PREFIX=` (vacío). Si tu
  proyecto escribía `File::path` a mano o asumía que empieza por `tenants/`, revísalo.
- **Migración nueva** `2026_11_20_000240_create_file_upload_intents_table`: `make art cmd="migrate"`. Añade el modelo `FileUploadIntent`
  (con `BelongsToTenant`: su prueba de aislamiento ya está registrada en `CoberturaDeAislamientoTest`).
- **Cuotas.** Con `PLAN_LIMITS_ENABLED=true` y multiempresa, `POST /files` y la subida directa aplican `max_storage_mb` (antes declarado
  pero sin usar) y el nuevo límite `max_file_mb` de `config/plans.php`. Si tu `config/plans.php` está personalizado, añade `max_file_mb`
  a `limits` y a cada plan (`null` = ilimitado). Una subida que supera la cuota responde 403 `storage_quota_exceeded`; si pasa del tamaño
  por archivo, 413 `file_too_large_for_plan`. Con límites apagados (por defecto) nada cambia.
- **`PlanLimitsInterface`**: `guarded()` y `ensureWithin()` ganan un parámetro final `int $unit = 1` (compatible hacia atrás). Si
  implementas la interfaz tú mismo, añádelo.
- **`FileServiceInterface`** gana `tenantStoragePath`, `storagePrefix`, `ensureFileSizeAllowed`, `withStorageQuota`, `usedStorageBytes` y `storageUsage`.
  El constructor de `FileService` recibe también `PlanLimitsInterface` (se resuelve por contenedor).
- Programa `files:purge-intents` (ya está en `routes/console.php`; si tienes tu propio scheduler, añádelo cada hora).
- **Subida por partes (S3 multipart)**: migración nueva `2026_11_21_000400_create_file_multipart_uploads_table` (`make art cmd="migrate"`), rutas `POST /files/uploads/multipart`, `POST|GET /files/uploads/{id}/parts` y `DELETE /files/uploads/{id}`, y variables `FILE_MULTIPART_*` (opcionales). `DirectUploadService` recibe también `MultipartStorageInterface` (se resuelve por contenedor) y `DirectUploadServiceInterface` gana `createMultipart`, `presignParts`, `listParts` y `abort`: si la implementas tú, añádelos. `files:purge-intents` ahora aborta en el proveedor las subidas por partes caducadas. Cambio aditivo.

### Sesiones, 2FA y verificación de correo (ola 5.3-A)

- **Migración** `2026_11_20_000280_create_user_sessions_table`: `make migrate`.
- **Tokens anteriores.** Los JWT emitidos antes no llevan `sid`: siguen valiendo hasta que caducan y su refresh se
  migra a una sesión al renovarse. Pasado `JWT_REFRESH_TTL` desde el despliegue pon `AUTH_REQUIRE_SESSION=true`
  para que los tokens sin sesión dejen de valer. Si generas tokens a mano (`JWTAuth::fromUser`), no tendrán sesión.
- **Refresh rotado.** Presentar el refresh inmediatamente anterior dentro de `AUTH_REFRESH_REUSE_GRACE` (10 s) da 401
  `refresh_superseded` sin cerrar la sesión (carrera legítima); fuera de la gracia o con uno más antiguo se revoca la
  sesión entera. El cliente debe guardar siempre el último `refresh_token` y no cerrar sesión ante `refresh_superseded`.
- **2FA de la API = el del back-office.** Se reutilizan `users.app_authentication_secret` y
  `app_authentication_recovery_codes`: quien ya tenga MFA en `/backoffice` recibirá desde ahora un desafío en
  `POST /auth/login` (`data.two_factor_required`, `data.challenge_token`, sin tokens) y debe completar
  `POST /auth/2fa/verify`. Adapta tu cliente o apaga la función con `AUTH_2FA_ENABLED=false`.
- **Correo de verificación.** `POST /auth/register` envía `VerifyEmailNotification` (apágalo con
  `AUTH_EMAIL_VERIFICATION_ON_REGISTER=false`); fija `AUTH_EMAIL_VERIFICATION_URL` a la pantalla del frontend. Nueva
  plantilla `email_verification` en `mail_template_configs` (`MailTemplateConfig::seedDefaults()`). El alias
  `verified` ahora es el de APYGG (403 `email_not_verified`). Cambiar el correo de un usuario
  (`UserService::update`) anula `email_verified_at` y reenvía la verificación (`AUTH_EMAIL_VERIFICATION_ON_CHANGE`).
  `UserService` ahora recibe `EmailVerificationServiceInterface` por constructor.
- **Dependencia declarada**: `pragmarx/google2fa ^9.0` en `composer.json` (antes solo transitiva de Filament).
- **Versión mínima de app.** `EnsureMinimumAppVersion` va en el grupo `api` pero está apagado (`APP_MIN_VERSION_ENABLED`).
- **Códigos de error nuevos** (`two_factor_invalid`, `email_not_verified`, `app_update_required`): documentados en
  `docs/api-response-structure.md`.
- `TokenServiceInterface` añade `generateSessionTokens()` y `validateRefreshToken()`, y `generateRefreshToken()` acepta
  claims; `AuthService` recibe tres dependencias nuevas (si lo extiendes, ajusta el constructor).

### Exportar, importar y PDF (ola 5.3-C)

1. `composer update`: se quitan `barryvdh/laravel-snappy`, `setasign/fpdf` y `setasign/fpdi` (ningún código del boilerplate
   los usaba) y se añaden `openspout/openspout` y `dompdf/dompdf`. Si tu proyecto usaba Snappy/FPDF, vuelve a añadir
   la dependencia que necesites o migra a `App\Contracts\PdfRendererInterface` (dompdf, PHP puro).
2. La imagen Docker ya no instala `wkhtmltopdf` (reconstruye con `make build`). Las librerías gráficas (`libx11`, `xfonts`...) siguen.
3. `make art cmd="migrate"` (tablas `data_exports` y `data_imports`) y `make art cmd="db:seed --class=PermissionSeeder"` /
   `RoleSeeder` (permisos `users.export`, `users.import`, `tenant-members.export`; este último va a `tenant-owner` y `tenant-admin`).
4. Opcional: variables `EXPORT_*` e `IMPORT_*` de `config/export.php`; `exports:prune` ya queda programado.

### Ajustes, feature flags y límites (ola 4.2-B)

- `Feature::enabled()` y el middleware `feature:` ahora pasan por `FeatureFlagServiceInterface` (BD + caché + overrides). Con tablas vacías el resultado es el de `config/features.php`, igual que antes. `CheckFeatureFlag` recibe la interfaz por constructor: si lo instancias a mano, pásasela.
- Migraciones `2026_11_19_000120` (flags y overrides) y `000121` (`tenants.plan`, `tenant_limit_overrides`): `make art cmd="migrate"` y `make art cmd="db:seed --class=PermissionSeeder"` (permisos nuevos).
- `ApiKeyService` recibe `PlanLimitsInterface`. Los límites están **apagados** (`PLAN_LIMITS_ENABLED=false`): sin activarlos nada cambia.
- El CRUD `/settings` valida y normaliza las keys declaradas en `config/settings.php`; la caché de `SettingsService` ya no lleva prefijo de tenant (son ajustes de plataforma).

### Revisión de aislamiento 4.3-B (hallazgos)

- **`security.allow_public_sharing` ahora se aplica** (por defecto `false`): subir o pasar a público un archivo (`is_public=true`) responde 403 `public_sharing_disabled` salvo que el ajuste esté activo (plataforma o tenant). Si tu proyecto usa archivos públicos, actívalo: `PUT /settings/registry/security.allow_public_sharing` o `SettingsRegistryInterface::setPlatform()`.
- Se elimina `security.session_timeout_minutes` de `config/settings.php` (no tenía efecto). Si lo habías leído en tu código, defínelo en tu `config/settings.php`.
- `FileService` recibe `SettingsRegistryInterface` por constructor; `SecurityService` recibe `ApiKeyServiceInterface`; `FileServiceInterface::assertVisibilityAllowed()`, `SecurityServiceInterface::blockIdentity()` y `ApiKeyServiceInterface::revokeAllForUser()` son métodos nuevos.
- La lista negra corta sesiones: bloquear (endpoint o Filament) sube `token_version` y revoca las API keys de las cuentas afectadas; los guards JWT y de API key rechazan a un bloqueado (caché de 60 s).
- Canales de broadcast: `presence-online` (global, con correo) se sustituye por `tenant.{tenantId}` (miembros activos, sin correo) y `online` (solo con multiempresa apagado, sin correo). Echo: `join('tenant.5')`.
- `Sequence`: con aislamiento activo, un `$tenantId` explícito distinto del contexto lanza `CrossTenantViolation`; fuera de un tenant solo dentro de `withoutTenant()`. `SequenceExhausted` es ahora `ApiException` 409 `sequence_exhausted` (ya no `RuntimeException`).
- Clave de límite inexistente en `PlanLimits`: `PlanLimitUnknownException` (422 `plan_limit_unknown`) en lugar de `InvalidArgumentException`.
- Los jobs de tenants `suspended`/`pending` fallan con `TenantInactiveForJob`.
- Con `Searchable` + `BelongsToTenant`: reindexa (`scout:flush`, `scout:import`) para que el índice lleve `tenant_id`.
- Al activar equipos de Spatie: tras `PERMISSION_TEAMS=true`, ejecuta `php artisan tenancy:enable-teams --resync-roles` (rehace los roles `tenant-*` por tenant). `tenancy:assign-orphans` solo asigna filas de miembros activos del tenant destino (`--user`, `--dry-run` por dueño).
- Nuevas reglas `App\Rules\TenantExists` / `TenantUnique`; `ArchTest` prohíbe `withoutGlobalScopes(` en `app/` salvo `[SoftDeletingScope::class]`.

### 1. Dependencias

```bash
make composer cmd="install"     # o composer update en tu entorno Docker
```

- Laravel 12 -> 13 (framework, PHP 8.5), `spatie/laravel-permission` 8.5,
  `filament/filament` 5.11 (+ Livewire 4), `chillerlan/php-qrcode` ^5.
- Se quitan `maatwebsite/excel` y **`laravel/socialite`** (el login social nunca
  tuvo rutas ni config). Si lo necesitas, añádelo en tu proyecto con sus rutas.
- Tras `composer install` corre `filament:upgrade` solo (en `post-autoload-dump`);
  los assets de Filament no se versionan.

### 2. Variables de `.env` nuevas

Copia de `.env.example` lo que falte. Las que tocan seguridad o despliegue:

| Variable | Qué hace |
|---|---|
| `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` | Admin inicial que crea `AdminUserSeeder` (sin ellas no se crea). |
| `TRUSTED_PROXIES` | Vacío = no se confía en `X-Forwarded-*`. Pon la IP o CIDR de tu proxy/balanceador. |
| `OCTANE_DISCONNECT_DATABASES` | `true` desconecta la BD al terminar cada petición. Por defecto `false`. |
| `DEVTOOLS_ALLOWED_EMAILS` | Quién entra a Telescope y Horizon fuera de `local` (además de los admin). `TELESCOPE_ENABLED` ahora es `false` por defecto en `.env.example`. |
| `PASSWORD_RESET_ALLOWED_ORIGINS` | Orígenes permitidos para `reset_url` en `forgot-password`. Vacío = se usan los orígenes de CORS (`ALLOWED_ORIGINS`); sin ninguno, solo el enlace por defecto. |
| `AUTH_LOGIN_MAX_ATTEMPTS`, `AUTH_LOGIN_MAX_ATTEMPTS_PER_IDENTITY`, `AUTH_LOGIN_LOCKOUT_MINUTES` | Bloqueo de login por IP y por identidad. |
| `FILE_DISK_PUBLIC`, `FILE_DISK_PRIVATE`, `FILE_TEMPORARY_URL_MINUTES`, `AWS_PRIVATE_BUCKET`, `FILE_MAX_SIZE_*` | Discos de archivos (ver punto 11). |
| `WEBHOOKS_ALLOW_HTTP`, `WEBHOOKS_ALLOWED_HOSTS` | Solo desarrollo: permitir `http` o hosts internos en webhooks (ver punto 9). No están en `.env.example`; léelas en `config/webhooks.php`. |
| `BACKOFFICE_ENABLED`, `BACKOFFICE_PATH`, `BACKOFFICE_BRAND` | Back-office Filament. `BACKOFFICE_ENABLED=false` lo apaga. |
| `SCRAMBLE_PUBLIC_DOCS` | Docs de la API (`/docs/api`) abiertas sin login. Ignorado en producción. Antes se abrían por nombre de entorno (`local`/`dev`). |
| `MAIL_MAILER`, `MAIL_*` | Ahora es el **mailer de respaldo** (punto 12). `.env.example` trae `log`. |
| `RESEND_API_KEY`, `RESEND_WEBHOOK_SECRET`, `MAIL_LOGS_RETENTION_DAYS`, `MAIL_WEBHOOKS_ENABLED`, `MAIL_QUEUE` | Correo. |
| `LLM_*`, `DOCS_*` | Gateway LLM y asistente RAG (solo si los usas). |
| `PERMISSION_TEAMS`, `PERMISSION_CACHE_STORE`, `PERMISSION_OCTANE_RESET` | Spatie. Teams viene apagado. |
| `CACHE_TTL_*`, `REDIS_CACHE_DB`, `REDIS_QUEUE_DB`, `REVERB_HOST_INTERNAL`, `REVERB_PORT_INTERNAL`, `MEILI_ENV`, `PORT`, `USE_FRANKENPHP_SSL`, `SERVER_NAME` | Infraestructura (Compose, Reverb, Meilisearch). |

### 3. Migraciones

```bash
make art cmd="migrate"
```

Migraciones nuevas, en este orden:

1. Las de sistemas añadidos (correo, LLM, menú, historial de notificaciones, lista
   negra, escaneos de seguridad, docs RAG, perfiles de usuario, tenants, device tokens).
2. `2026_11_19_000001_add_token_version_to_users_table`: `users.token_version` (ver punto 6).
3. `2026_11_19_000002_adapt_rbac_tables_for_spatie_permission`: añade `guard_name` a
   `roles` y `permissions` (los datos existentes quedan en `api`), crea las tablas de
   Spatie y **copia** `user_role`, `user_permission` y `role_permission`.
4. `2026_11_19_000003_drop_legacy_rbac_pivot_tables`: borra las tres pivotes antiguas
   (su `down()` las recrea con los datos).
5. `2026_11_19_000020_add_deleted_at_to_webhook_deliveries_table`: sin esta columna,
   cualquier consulta sobre entregas daba 500.
6. `2026_11_19_000030_add_app_authentication_to_users_table`: columnas cifradas del MFA
   TOTP del back-office.
7. `2026_11_19_000040_convert_json_columns_to_jsonb`: las columnas `json` pasan a
   `jsonb` (`ALTER ... TYPE jsonb USING col::jsonb`). Reescribe las tablas, así que en
   `logs_api` y `logs_activity` grandes hazlo en una ventana de mantenimiento. Es
   idempotente y reversible. Desde ahora: **migraciones nuevas con `$table->jsonb()`**.

Comprueba después: `make art cmd="permission:show"`.

### 4. Permisos y roles: re-sembrar

```bash
make art cmd="db:seed --class=PermissionSeeder"
make art cmd="db:seed --class=RoleSeeder"
make art cmd="permission:cache-reset"
```

- `PermissionSeeder` trae solo los permisos que usa el código, más el nuevo
  `backoffice.access`. Se quitan los de ejemplo (`posts.*`, `comments.*`,
  `system.backup`, `system.users`) y los roles `guest`, `moderator`, `editor`: **si
  tu proyecto los usa, declara tu propio seeder** (no se borran de tu BD solos).
- Los roles base son `admin`, `manager`, `user`. `admin` pasa todo por `Gate::after`.
- `user` y `guest` ya no listan usuarios, roles ni permisos.
- Si cambias roles o permisos con SQL a mano, ejecuta siempre `permission:cache-reset`.

### 5. Rutas y middleware (rompe el código de tu proyecto)

- Se eliminan `CheckPermission`, `CheckRole` y el mapa método HTTP -> acción.
  Cada ruta declara permiso exacto **y guard**: `->middleware('permission:users.read,api')`
  (varios con OR: `permission:a.read|b.read,api`). Un `permission:users` sin acción
  ya no funciona.
- `User::hasPermission()`/`hasRole()` propios desaparecen: usa `$user->can('x.y')`,
  `checkPermissionTo()`, `hasRole()`, `assignRole()`, `givePermissionTo()`.
- Nunca `DB::table()` sobre las tablas de roles y permisos (no refresca la caché).
- `ApiController` y todo CRUD genérico de `Controller` desaparecen: hay una sola
  base abstracta `Controller`. Cada controller declara sus acciones con Form
  Requests **tipados** (`store(StoreXRequest $request)`); un parámetro sin tipo
  da 500.
- Servicios por interfaz: inyecta `App\Contracts\XServiceInterface`, no la clase
  concreta. Se eliminan los repositorios (`App\Repositories`), los DTOs y los enums
  de `app/Enums`. Si tu código los importaba, copia lo que uses a tu proyecto.
- No hay propiedades estáticas mutables en `App\` (Octane): usa constantes, `config()`
  o un binding.
- Rutas de prueba `test/sentry/*` solo en `local` y `testing`.

### 6. Autenticación y tokens

- Los tokens llevan el claim `tv` (`users.token_version`, 0 por defecto). Un cambio o
  reset de contraseña sube la versión e invalida todas las sesiones del usuario; los
  tokens emitidos antes (sin `tv`) siguen valiendo mientras la versión sea 0.
- El refresh token ya no sirve como access token (`ApiJwtGuard` rechaza `type=refresh`).
- Las respuestas de auth (`/auth/login`, `register`, `refresh`...) **ya no llevan
  `meta`**: `{ success, message, data }`. Si tu frontend lee `meta.timestamp` o
  `meta.request_id` de ahí, quítalo. Los listados paginados siguen con `meta` de
  paginación y los errores del handler con `meta` de trazabilidad (ver
  `docs/api-response-structure.md`).
- `forgot-password` responde igual exista o no el email, envía en cola, y `reset_url`
  solo se acepta con origen en `PASSWORD_RESET_ALLOWED_ORIGINS`. El enlace siempre
  lleva el token; el reset es de un solo uso y caduca.
- Bloqueo de login por identidad (no solo por IP), contadores atómicos y lista negra
  aplicada en login, refresh y registro.
- La IP de Cloudflare solo se acepta si `REMOTE_ADDR` es de Cloudflare.
- El email se guarda y compara en minúsculas.
- El registro de seguridad de login/logout sale ahora de los eventos de Laravel
  (`Login`/`Logout`, que `AuthService` dispara) y deja **un solo** registro por
  suceso. `UserLoggedIn`/`UserLoggedOut` quedan solo para webhooks. Si tenías un
  listener propio de `LogAuthEvents` (borrado), escucha el evento `Login`.

### 7. Usuarios, perfil y roles

- `PUT /users/{id}` **ya no acepta** `role_ids`, `password` propia ni `email` sin el
  permiso `users.update`: los roles van por `POST /users/{id}/roles`, la contraseña
  por `change-password`.
- `/user/profile` y `/user/preferences` tienen controller propio; email, contraseña,
  roles y documento dan 422 por esa vía.
- Jerarquía de roles: solo un admin da el rol `admin` o toca a otro admin; nadie
  asigna roles con permisos que no tiene (también en `POST /users` con `role_ids`);
  `roles.create/update` con `permission_ids` exige `roles.manage-permissions`; no se
  puede quitar ni borrar al último admin.
- `/admin/logs` exige `system.logs` y `system.logs-delete`; los logs redactan
  contraseñas, tokens y secretos. `/search` exige `users.read` y devuelve lo mínimo.
- Telescope y Horizon solo abiertos en `local`; fuera, admin o `DEVTOOLS_ALLOWED_EMAILS`.
- El registro crea perfil y rol por defecto (`AUTH_DEFAULT_ROLE`, `user`) en una transacción.

### 8. Entrada sin saneado global

Se eliminan `SanitizeInput`, `TransformRequestMiddleware`, `TransformResponseMiddleware`
y `BaseFormRequest::sanitizeInput()`. La API guarda lo que recibe, validado con Form
Requests (solo `TrimStrings` y `ConvertEmptyStringsToNull` de Laravel). Consecuencias:
`"1"`/`"no"` ya no se convierten en booleanos (declara `boolean` en el Form Request),
el HTML no se quita (escapa al pintar) y las contraseñas no se recortan. Revisa tus
Form Requests y tu frontend.

### 9. Webhooks salientes

- **Firma nueva**: `sha256` de `"timestamp.cuerpo"`, con cabeceras
  `X-Webhook-Timestamp` y `X-Webhook-Signature`. Los receptores que verificaban solo
  el cuerpo deben actualizarse.
- Solo `https` y sin redes privadas (loopback, link-local, metadata, CGNAT, IPv6
  locales); sin redirecciones. Se valida al crear, editar y antes de cada envío. En
  desarrollo: `WEBHOOKS_ALLOW_HTTP=true` y `WEBHOOKS_ALLOWED_HOSTS`. Revisa los
  destinos ya guardados.
- Cada webhook recibe solo eventos de su dueño. El secreto solo se ve al crear y rotar.
- **Cola `webhooks`**: antes ningún supervisor de Horizon la consumía (los webhooks
  no se enviaban). Ahora hay `supervisor-webhooks`: **reinicia Horizon**
  (`php artisan horizon:terminate`) tras desplegar.
- **Timeouts**: sube `REDIS_QUEUE_RETRY_AFTER` a `660` (o a un valor mayor que el
  timeout del job más largo); con `60` un job lento se ejecuta dos veces. Nuevas
  variables `JOB_TIMEOUT`, `SECURITY_SCAN_TIMEOUT`, `WEBHOOK_MAX_TIMEOUT` (120) y
  `WEBHOOK_JOB_TIMEOUT` (150). El `timeout` de un webhook ya no puede pasar de 120 s;
  los guardados con más se recortan al enviar.
- **Reintentos**: un 4xx del destino (salvo 408 y 429) es fallo permanente y no se
  reintenta.

### 10. API keys

- **Una key sin scopes ya no tiene acceso** (el total es `"*"`). Revisa las keys
  existentes y asígnales scopes.
- El guard `api-key` es real (`auth:api-key` antes no funcionaba). Las keys `test`
  no valen en producción. La rotación no alarga la caducidad.

### 11. Archivos

- El tipo se decide por contenido; SVG, HTML, XML, JS y PHP se rechazan siempre; el
  nombre en disco es un ULID; hay tamaño máximo por tipo.
- Los **privados** (`is_public=false`) viven en `FILE_DISK_PRIVATE` y se descargan solo
  por el endpoint autorizado (`files.manage`) o por URL firmada temporal
  (`FILE_TEMPORARY_URL_MINUTES`). En S3/MinIO: `FILE_DISK_PUBLIC=s3`,
  `FILE_DISK_PRIVATE=s3_private`, `AWS_PRIVATE_BUCKET=<bucket privado>`.
- **Falta mover a mano** los privados que ya estaban en el disco público al privado
  (no hay comando): copia los ficheros y actualiza `files.disk`/`path` según tu caso,
  y retira los originales del disco público.
- Se eliminan las páginas `public/*.html` de prueba.

### 12. Correo: un solo camino

Todo correo es un Mailable o Notification de Laravel, en cola. `MAIL_MAILER` pasa a
ser el mailer de respaldo cuando no hay cuentas en BD; el mailer por defecto es
`accounts`. Se eliminan `SendMailJob` y `SendPasswordResetEmailJob`; `MailService`
queda como fachada fina. Si enviabas con `Mail::mailer('x')` o con tu propio job,
migra a un Mailable e implementa `HasMailTemplate` si quieres poder pausarlo.

### 13. Back-office (opcional)

Filament 5 en `/backoffice`: sesión web + permiso `backoffice.access` (se siembra en
el punto 4; dáselo al rol que corresponda) + MFA TOTP obligatorio. Si no lo quieres:
`BACKOFFICE_ENABLED=false`, o quítalo siguiendo `docs/backoffice.md`. Las rutas del
panel y de Livewire no pasan por el render JSON de la API.

### 14. Código muerto eliminado

Si tu proyecto lo usaba, cópialo a tu repo antes de actualizar:

- DTOs (`app/DTOs/*`), Enums (`app/Enums/*`), `app/Events/Broadcasting/*`.
- Helpers `StringHelper`, `DateHelper`, `SecurityHelper` (usa Carbon).
- Jobs `ProcessActivityLogJob`, `ProcessApiLogJob`, `SendNotificationJob`.
- Reglas `ExistsInDatabase`, `UniqueInDatabase`, `ValidBase64Image`, `ValidDateRange`,
  `ValidEmail`, `ValidJson`, `ValidPhone`, `ValidUuid`. Quedan `StrongPassword`,
  `NotBlacklisted`, `SafeWebhookUrl` y `AllowedResetUrl`.
- `FcmService`, `QrCodeService`, `DeviceTokenController` y el modelo `DeviceToken`
  (la tabla `device_tokens` se conserva; bórrala si no la usas).
- `SocialAuthController`, `SocialAuthService` y su paquete (login social).
- `App\Exceptions\Handler` (el handler real vive en `bootstrap/app.php`).
- `LogAuthEvents` (ver punto 6).

### 14b. Arranque de proyecto y nombre visible

- Nuevo `APP_TITLE` en `.env` (`config('app.title')`): la ficha de `/` y el título de
  `/docs/api` ya no dicen "APYGG API" fijo. Añádelo a tu `.env` (`APP_TITLE="Mi API"`);
  sin él se sigue viendo "APYGG API". Si tu test comprueba el nombre de la ficha,
  compáralo con `config('app.title')`.
- `make down` / `make down-v` ahora paran también los servicios de los perfiles
  (`dev`, `prod`, `search`); antes dejaban Mailpit/S3 colgados.
- `DEV=true` en `compose.env` activa el perfil dev en todos los `make` (antes solo con
  `make up-dev`). `DEV=false` ya no activa el perfil (antes cualquier valor lo hacía).
- `make validate` es más estricto: falla con puertos repetidos, `REDIS_PASSWORD` vacío
  o puertos de BD/Redis/S3/Mailpit publicados fuera de `127.0.0.1`.
- Proyectos nuevos: `docs/new-project.md`. Proyectos existentes: nada que hacer; para
  recibir mejoras, `git remote add upstream <repo-apygg>` y `make upgrade-check`.

### 14c. Multiempresa: el modelo `Tenant` cambió

El multi-tenancy anterior (`Tenant::$currentTenant`, `ResolveTenant` sin registrar, `TenantScope` que
no filtraba sin tenant) se sustituye por el de `docs/multi-tenancy.md`. Si tu proyecto lo usaba:

- Migraciones nuevas `2026_11_19_000050` (`tenants.status` string y `parent_id`; **elimina `is_active`**,
  convirtiendo `false` en `suspended`) y `000051` (`tenant_user`). `make art cmd="migrate"`.
- `Tenant::setCurrent/resolveCurrent/clearCurrent/hasCurrent` desaparecen: usa
  `app(App\Support\Tenancy\TenantContext::class)` (`current()`, `set()`, `run()`).
- `Tenant::users()` ya no es `hasMany` por `users.tenant_id`: es many-to-many vía `tenant_user`. Migra a
  mano tus usuarios con `tenant_id` a membresías.
- `scopeActive` de `Tenant` pasa a `scopeOperational` (el `active()` heredado ahora es «no borrado»).
- `BelongsToTenant` es fail-closed (con `MULTI_TENANCY_ENABLED=true`, sin tenant en el contexto lanza
  `MissingTenantContext`); `scopeAllTenants`/`scopeForTenant` desaparecen: usa `TenantQuery::withoutTenant()`
  o `TenantContext::run()`.
- Middleware: alias `tenant` (`['auth:api', 'tenant']`); cabecera `X-Tenant` (antes `X-Tenant-ID`); ya no hay
  resolución por claim JWT ni tenant por defecto. `config/multi-tenancy.php` cambia de claves.
- Re-siembra permisos: `make art cmd="db:seed --class=PermissionSeeder"` (nuevo `tenants.access-any`).
- Variables nuevas: `MULTI_TENANCY_ENABLED`, `TENANT_RESOLUTION`, `TENANT_HEADER`, `TENANT_BASE_DOMAIN`.

### 14d. Multiempresa: recursos por tenant y equipos de Spatie

Solo afecta si activas `MULTI_TENANCY_ENABLED=true` (con `false` todo sigue igual). Migraciones nuevas:
`2026_11_19_000060` (`tenant_id` NULLABLE + índice + FK en `files`, `webhooks`, `webhook_deliveries`, `api_keys`),
`000061` (`tenant_id` con índice, sin FK, en los 3 logs) y `000062` (equipos de Spatie; solo actúa con `PERMISSION_TEAMS=true`).

- Con datos existentes: `php artisan tenancy:assign-orphans <tenant>` asigna los registros sin tenant (si no,
  quedan invisibles para los tenants).
- Rutas `files`, `webhooks`, `api-keys` con `tenant`: los clientes mandan `X-Tenant`. Las API keys fijan su
  tenant solas; `api-key:create` exige `--tenant=`. Una key sin tenant, de tenant suspendido o de usuario ya no
  miembro deja de autenticar.
- Los eventos sin tenant (login...) ya no llegan a webhooks; el job de webhooks encolado antes de actualizar
  (sin tenant guardado) falla cerrado: vacía la cola de `webhooks` antes de activar multiempresa.
- Spatie teams: ver `docs/permissions.md` §Teams (`tenancy:enable-teams`); reversible pero destructivo.
- `User::isAdmin()` con teams = admin del tenant activo o de la plataforma; nuevo `User::canEnterTenant()`.
- `CacheService`: dentro de `TenantQuery::withoutTenant()` las claves no llevan prefijo de tenant.

### 14d-bis. Multiempresa: alta, invitaciones y miembros

Solo si activas `MULTI_TENANCY_ENABLED=true`. Migraciones nuevas `2026_11_19_000100` (`tenants.trial_ends_at` y
`tenant_user.role`, por defecto `member`) y `000101` (`tenant_invitations`). Pasos: `make art cmd="migrate"`;
`make art cmd="db:seed --class=RoleSeeder"` (nuevos permisos `tenant-members.*`, `tenant-invitations.manage` y roles
`tenant-owner/admin/member`); y, si ya tenías membresías, nombra a los dueños:
`php artisan tenancy:set-role <tenant> <email> owner` (sin dueño, solo la plataforma puede administrar miembros).
Variables nuevas en `.env.example` (`TENANT_SIGNUP_*`, `TENANT_INVITATION_*`, `TENANT_TRIAL_DAYS`). `TenantStatus`
gana el caso `pending`. Detalle: `docs/tenant-onboarding.md`.

### 14e. Actualizar la imagen por seguridad (PHP / FrankenPHP)

La imagen de la app fija la versión exacta en `docker/app/Dockerfile`
(`dunglas/frankenphp:<franken>-php<php>-bookworm`). Desde PHP 8.5.12 hay
correcciones de dos vulnerabilidades críticas de ejecución remota sin
autenticación (CVE-2026-109618 y CVE-2026-109619): una imagen con PHP 8.5.3
(la antigua `1.11-php8.5-bookworm`) es vulnerable. Pasos:

1. Cambia el `FROM` a la última etiqueta fijada (hoy `1.13.6-php8.5.13-bookworm`;
   consulta las etiquetas en Docker Hub, `dunglas/frankenphp`).
2. `make build` (reconstruye la imagen sin tocar datos) y `make up` para
   reiniciar los contenedores con la imagen nueva.
3. Comprueba: `make sh` y luego `php -v` y `php -m` (pcov, redis, intl,
   pdo_pgsql, gd, zip, bcmath, pcntl, opcache).
4. `make check` y `make test`.

Repite esto con cada parche de seguridad de PHP; no uses etiquetas flotantes
(`php8.5`) en producción.

### 15. Nomenclatura Docker uniforme con guion (renombrar volúmenes)

Contenedores, imágenes, volúmenes y red pasan de `<proyecto>_<x>` a `<proyecto>-<x>`
(p. ej. `apygg_postgres` → `apygg-postgres`, volumen `apygg_pgdata` → `apygg-pgdata`,
red `apygg_network` → `apygg-network`; también `meili`, `s3`, `s3_init` → `s3-init`).
Los volúmenes viejos contienen tus datos: si solo haces `make up`, Compose crea volúmenes
vacíos nuevos y parecerá que perdiste la BD. Procedimiento (con ventana de parada):

```bash
make backup                      # red de seguridad
make down                        # para el stack (sin -v: NO borres volúmenes)
make rename-volumes DRY=1        # simula: lista qué copiaría
make rename-volumes              # copia <p>_pgdata → <p>-pgdata (y redis, meili, s3) con alpine
make up                          # levanta con los nombres nuevos
make doctor                      # todo en verde; comprueba /health y que tus datos están
docker volume rm <proyecto>_pgdata <proyecto>_redisdata ...   # solo tras comprobar (lo imprime el script)
```

El script es idempotente, no borra nada y se niega a copiar si hay contenedores usando el volumen
viejo. Los contenedores/redes viejos desaparecen con `make down`. Actualiza también scripts de
deploy, monitorización o cron propios que llamen a `docker exec <proyecto>_app` (ahora `<proyecto>-app`)
y los filtros `docker ps --filter name=^<proyecto>_`.

### 15b. i18n: idioma, zona horaria y moneda

- Migración `2026_11_19_000140` (`make migrate`): `users.locale`, `tenants.locale/timezone/currency` y **`users.timezone`
  pierde su `DEFAULT 'UTC'`** (NULL = hereda). Los usuarios existentes conservan su `UTC` como valor explícito; si quieres
  que hereden, `UPDATE users SET timezone = NULL WHERE timezone = 'UTC'`.
- Las traducciones pasan de `resources/lang/es.json` (no se usaba: era un JSON anidado) a `lang/es` y `lang/en` en PHP;
  si tenías claves propias en el JSON, muévelas a `lang/<idioma>/`. Cada idioma nuevo exige los mismos archivos y claves
  (`tests/Unit/LangParidadTest.php`).
- `BaseFormRequest::getCommonMessages()/getCommonAttributes()` devuelven `[]`: los textos comunes salen ahora de
  `lang/<idioma>/validation.php`. Si un proyecto dependía de ese texto exacto, ajústalo en `lang/`.
- Los textos del handler global ya no son literales: `lang/*/messages.php` (`errors.*`). Con el idioma por defecto
  `es`, el 404 sin ruta y el 422 pasan de inglés a español (`Endpoint no encontrado.`, `Los datos proporcionados no son válidos.`);
  el `code` no cambia. Con `Accept-Language: en` salen en inglés.
- `SetApiLocale` es middleware global; `ResolveTenant` ahora recibe `LocaleResolverInterface` (constructor).
- Nuevo permiso `tenant-settings.manage` (`make seed` o `PermissionSeeder`; el rol `admin` lo recibe por `RoleSeeder`).
- Docs: `docs/internationalization.md` se sustituye por `docs/i18n.md`.

### 15c. Integraciones y webhooks entrantes genéricos (ola 5.1-B/C)

- **Migración nueva**: `2026_11_20_000120_create_inbound_webhook_receipts_table` (`php artisan migrate`).
- **Config y `.env`**: `config/integrations.php` (copiarlo) y variables `SMS_MODE` (por defecto `fake`), `SMS_DRIVER`, `SMS_FROM`,
  `SMS_HTTP_URL`, `SMS_HTTP_TOKEN`, `INBOUND_WEBHOOK_*`, `INTEGRATIONS_*`. En producción pon `SMS_MODE=real` solo si vas a enviar SMS.
- **Provider nuevo**: añade `App\Providers\IntegrationsServiceProvider` a `bootstrap/providers.php`.
- **`POST /webhooks/mail` (Resend)**: la URL no cambia (alias de `/webhooks/inbound/resend`) y `RESEND_WEBHOOK_SECRET` sigue igual,
  pero ahora el evento se guarda en `inbound_webhook_receipts` y se procesa **en cola** (necesitas un worker; con `QUEUE_CONNECTION=sync` va
  en la misma petición). `MailWebhookController` se eliminó: si lo referenciabas, usa `InboundWebhookController`. Los errores llevan
  `code` estable (`webhook_signature_invalid`, `webhook_timestamp_expired`, `webhook_not_configured`...); un payload sin `type`/`data.email_id` sigue dando 422.
- **Scheduler**: `webhooks:inbound-sweep` (cada 10 min, ya en `routes/console.php`) reencola recibos atascados y aplica la retención.
- **`NotificationService`** recibe ahora `SmsSenderInterface` en el constructor y `sendSms()` envía de verdad (antes era un stub que solo
  registraba): con `SMS_MODE=fake` no sale nada; revisa quién lo llamaba. Si lo instancias a mano, pasa el nuevo argumento.
- Si registras tus propios proveedores de webhook entrante, usa `config/integrations.php` -> `inbound_webhooks.providers` (`docs/integrations.md` §7).

### 15d. Notificaciones multicanal (ola 5.2-A, `docs/notifications.md`)

Rompe si tu proyecto usaba el servicio viejo:

- **`NotificationServiceInterface` cambia por completo.** Desaparecen `sendEmail`, `sendDatabase`, `sendSms`, `sendPush`
  (era un stub), `sendMultiChannel` y `getHistory`. Ahora: `notify($users, 'tipo', $params, ?$tenant)` y `notifyNow()`.
  Correo suelto: `MailServiceInterface::send()`; SMS: `SendSmsJob` o un tipo con canal `sms`.
- **`NotificationHistory` y la tabla `notification_history` se eliminan** (migración `2026_11_20_000204`; su `down()` la recrea
  vacía). Lo sustituye `notification_deliveries` (por usuario y canal, con estado e intentos). No hay migración de datos:
  si necesitabas el historial, expórtalo antes.
- La tabla `device_tokens` (sin modelo ni uso) se sustituye por `user_devices` (migración `000200`). Si tu proyecto la usaba, copia los tokens antes.
- Tablas nuevas: `notification_preferences`, `notification_settings`, `inbox_notifications` (con `tenant_id`, `BelongsToTenant`), migraciones `000201`-`000203`.
- Variables nuevas (`.env.example`): `PUSH_MODE` (`fake` por defecto), `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`, `NOTIFICATIONS_*`.
- Comandos programados nuevos: `notifications:sweep` (cada 10 min) y `notifications:purge` (diario). Reinicia el scheduler.
- Con multiempresa activa, notificar sin tenant en el contexto omite la bandeja (`no_tenant_context`): pasa `$tenant` a `notify()`.
- `Job::notifyFailure()` envía ahora con `MailServiceInterface` directamente.

### 16. Verificación final

```bash
make art cmd="route:list"        # cada ruta con permission:x.y,api
make check
make testdb-migrate && make test
```

## Redacción unificada de logs

`config/logging.php`: `redact_keys` y `redact_key_fragments` pasan a `redact.keys` y `redact.key_fragments` (más `mode`, `patterns`, `max_depth`, `max_string`). Los canales llevan `tap => [RedactLogs::class]` y `config/sentry.php` define `before_send`/`before_breadcrumb`. Los emails en logs de BD quedan enmascarados (`a***@b***.com`). Ver `docs/logging.md`.

## Correo: supresión, dominios reservados y marca

- Migración `2026_11_20_000220` (`mail_suppressions`) y permiso `mail.suppressions` (`PermissionSeeder`; asígnalo a quien gestione correo).
- **Dominios reservados**: ahora NUNCA se envía a `@example.com/.org/.net` ni `.test/.invalid/.localhost/.example`. Si tus tests envían correo a esas direcciones (las factories de usuario usan `@example.com`), añade `<env name="MAIL_BLOCK_RESERVED_DOMAINS" force="true" value="false"/>` a `phpunit.xml` o cambia el dominio. En local con Mailpit pon `MAIL_BLOCK_RESERVED_DOMAINS=false` si usas esas direcciones.
- `MailSendPolicyInterface` gana `deniedRecipients()`; si tienes una implementación propia, añádela.
- `ResendMailEventsHandler` recibe `MailSuppressionServiceInterface` en el constructor (resuelto por el contenedor).
- Si publicaste tus propias plantillas `vendor/mail/*/message.blade.php`, adopta `BrandResolverInterface` (ver `docs/mail-system.md` §Marca) o seguirán mostrando `config('app.name')`.


## Privacidad (ola 5.2-E, `docs/privacy.md`)

- Migraciones `2026_11_20_000320`-`000323` (`legal_documents`, `user_consents`, `personal_data_exports`, `account_deletion_requests`) y permisos `legal-documents.read` / `legal-documents.manage` (`PermissionSeeder`; asígnalos a quien publique documentos legales).
- **Contribuidores**: si tu proyecto guarda datos personales en tablas propias, implementa `App\Contracts\Privacy\PersonalDataContributor` (exportar sin secretos y anonimizar/borrar) y regístralo con `$this->app->tag([...], 'privacy.contributors')`. Sin él, el export y el borrado de cuenta no los cubren.
- Nuevo middleware global `EnsureLegalConsents` en el grupo `api`, **apagado** (`PRIVACY_CONSENTS_ENFORCE=false`). Al activarlo, los clientes deben manejar `409 consent_required`.
- Tipos de notificación nuevos (`data_export_ready`, `account_deletion_scheduled`, `account_deletion_blocked`): añade sus textos si sobrescribes `lang/*/notifications.php`.
- Programador: `privacy:process-deletions` (cada hora) y `privacy:purge` (03:45). Necesitas un worker de cola para el export y el borrado.


## Back-office: recursos de plataforma (ola 8.2-B, `docs/backoffice.md`)

- Sin migraciones. Permisos nuevos `tenants.read` y `tenants.manage` (`PermissionSeeder`; el rol `admin` los recibe al resembrar; asígnalos a quien administre empresas desde el panel).
- `config/audit.php`: `reason_required` incorpora `tenant.suspend`, `tenant.reactivate`, `tenant.limit-override` y `mail-suppression.release`. Si fijas `AUDIT_REASON_REQUIRED` en tu `.env`, añade esas acciones.
- Nuevo `TenantAdminServiceInterface` (enlazado en `ContractServiceProvider`). `Failure::guard()` trata ahora como error de dominio cualquier `ApiException`.

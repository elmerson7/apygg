# Actualización para proyectos derivados

Qué rompe o exige acción al pasar un proyecto creado desde APYGG a una versión
más reciente. Cada sección empieza por la versión de origen. Los cambios sin
impacto en un proyecto existente están en `CHANGELOG.md`.

> Antes de empezar: **backup de la BD** (`make backup`) y una rama aparte.
> Aplica los pasos en este orden. Los comandos son los de Make del repo
> (nunca `php artisan` en el host).

## De 1.0.0 a la versión actual (Unreleased)

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

### 16. Verificación final

```bash
make art cmd="route:list"        # cada ruta con permission:x.y,api
make check
make testdb-migrate && make test
```

# APYGG — Guía para agentes y LLMs

> 📌 **Este archivo es el primero que deben leer las LLM / agentes** al trabajar
> en este repo, y es la **fuente de verdad**. Sus reglas prevalecen sobre
> cualquier contradicción en `docs/*.md`. Si una doc secundaria muestra un
> ejemplo que contradice lo aquí escrito, aplica este archivo y corrige la otra
> doc, no al revés.

APYGG es un **boilerplate de API**: el código que hay aquí es infraestructura
reutilizable, no un producto. Antes de añadir algo, pregúntate si sirve a
cualquier proyecto que parta de esta base. Si es lógica de un negocio concreto,
no va aquí.

## Documentación obligatoria — leer antes de implementar

1. `docs/CODING_STANDARDS.md` → patrones de código, naming y ejemplos
2. `docs/CONTROLLER_CONVENTIONS.md` → estructura de controllers
3. `docs/api-routes-conventions.md` → rutas y prefijos
4. `docs/api-response-structure.md` + `docs/error-handling.md` → contrato de respuestas
5. `docs/api-contract.md` → contrato OpenAPI (`docs/openapi.json`), tests de contrato y tipos para los fronts
5. `docs/permissions.md` → roles, permisos, middlewares `permission:`/`role:`, super admin, teams

## Proyecto nuevo y entorno local

- Arrancar un proyecto derivado: `make init NAME=<slug>` → `make setup` → `make doctor`.
  Nunca copies `compose.env.example` a mano con `PROJECT=apygg`: colisiona con otros stacks.
- `make doctor` diagnostica el entorno; `make upgrade-check` lista lo que trae `upstream`.
- Para trabajar en el boilerplate con varios worktrees: `make wt-env NAME=<tarea>`.
- Guía completa: `docs/new-project.md`.

## Comandos — TODO va por Docker vía Make (NUNCA en el host)

- ❌ `php artisan <cmd>` directamente en el host (no hay PHP ni BD locales)
- ✅ `make art cmd="<comando>"` → ejecuta `php artisan` DENTRO del contenedor
- ✅ `make sh` → abre una shell en el contenedor para trabajar interactivamente
- Migraciones, tinker, colas, tests: **siempre** por `make`.
- `make help` lista todos los targets disponibles.
- Los targets viven en `make/*.mk` (uno por tema); el `Makefile` raíz solo define variables e incluye esos archivos.

## Quality checks — OBLIGATORIO tras cualquier cambio PHP

- ✅ `make check` → `pint` (formato PSR-12) + `phpstan` (análisis estático)
- Equivale a `make pint` + `make phpstan` por separado.
- Si phpstan devuelve errores, corrígelos antes de dar el trabajo por terminado.
- PHPStan corre a **nivel 6** (`phpstan.neon`), con `reportUnmatchedIgnoredErrors: true`,
  **sin `excludePaths` ni baseline** y con un único `ignoreErrors` justificado.
  Un error nuevo se **arregla** (tipo, `@property`, genérico), no se ignora: ver
  "Análisis estático" en `docs/CODING_STANDARDS.md`.
- Arrays en firmas: siempre con tipo de valor (`array<string, mixed>`, `list<int>`);
  relaciones y scopes con genéricos (`HasMany<Webhook, $this>`, `Builder<static>`).
- `make install-hooks` instala los git hooks que ejecutan esto en cada commit.

## Contrato OpenAPI

Tras cambiar rutas, Form Requests o la forma de una respuesta: `make openapi` (regenera
`docs/openapi.json`, que se commitea) y `make test-contract`. Un test de la suite por defecto falla si
el archivo está desactualizado. Detalle en `docs/api-contract.md`.

## Stack

- Laravel 13 · PHP 8.5 · FrankenPHP/Octane (**no** nginx, **no** apache) · Filament 5 (back-office opcional)
- PostgreSQL 18 · Redis 7 · Docker Compose
- JWT · Spatie Permission 8 · Horizon · Reverb · Scout/Meilisearch
- Pest · Pint · PHPStan (Larastan)

Detalle completo en `TECH_STACK.md`.

## Convenciones de código

- `snake_case` → columnas de BD, atributos de modelos, claves de config y de
  respuestas JSON
- `camelCase` → métodos, variables, parámetros
- `PascalCase` → clases, namespaces, traits
- **IDs**: el `Model` base usa auto-incremental (`int`). Usa el trait `HasUuid`
  cuando el recurso deba exponer un identificador no adivinable. Ver
  `docs/id-strategy.md`.

## Back-office (Filament, opcional)

Panel web interno **solo para el dueño de la plataforma** (no es el panel de los clientes de un
SaaS: ese es API + frontend propio). Módulo **quitable**: todo vive en `app/Filament/`,
`app/Providers/Filament/BackofficePanelProvider.php`, `config/backoffice.php` y `docs/backoffice.md`.

- Ruta `/backoffice` (`BACKOFFICE_PATH`; **no** `/admin`, que es de la API). `BACKOFFICE_ENABLED=false` → el panel no se registra.
- Acceso: sesión **web** (guard `web`) + permiso `backoffice.access` (guard `api`, como todos; `User::$guard_name = 'api'` lo resuelve) + **MFA TOTP obligatorio**. Inactivos y lista negra no entran.
- Un recurso nuevo **no duplica lógica**: autoriza con la policy del modelo (o una `PermissionBasedPolicy` si la API solo usa middleware) y **escribe llamando al servicio** (`UserService`, `RoleService`...). Nada de `$record->update()` directo si existe servicio. Errores de dominio → `Failure::guard()`.
- Sin policy, Filament **deniega**: registra la del modelo (ver `boot()` del provider). Los recursos de solo lectura usan `IsReadOnly`. Logs: muestra con `Pretty::json()` (redacta secretos).
- Sin estado estático ni cachés en recursos (Octane). El panel fija `authGuard('web')`: no uses `auth()->user()` sin guard donde pueda correr el guard `api`.
- Tests: `tests/Feature/Backoffice/` (Livewire + helpers de Filament). Detalle y cómo quitarlo: `docs/backoffice.md`.

## Arquitectura

```
app/
├── Console/Commands/ → comandos artisan
├── Contracts/        → interfaces de servicios
├── Events/           → eventos del sistema
├── Http/             → controllers, middleware, requests, resources
├── Jobs/             → trabajos en cola
├── Logging/          → handlers y processors de Monolog
├── Models/           → modelos Eloquent (heredan de App\Models\Model)
├── Services/         → lógica de aplicación
└── Traits/           → traits reutilizables
```

### Servicios: se inyectan por interfaz

Cada servicio de `app/Services` con lógica de aplicación **implementa** una
interfaz de `app/Contracts/` y se enlaza en `ContractServiceProvider`
(`bind`: instancia nueva por resolución, sin estado entre peticiones en Octane).
Controllers, servicios, jobs (en `process()`), listeners, comandos (en `handle()`)
y middlewares reciben **la interfaz** por constructor o método. Nada de
`new Servicio` ni `app(Servicio::class)`.

- Las interfaces describen lo que necesita la app, no detalles internos.
- Las reglas de negocio lanzan excepciones de dominio (`BusinessLogicException`
  y las de `app/Exceptions`), nunca `\Exception` genérica: el handler global las
  convierte en 4xx. Los controllers no hacen `try/catch` que solo re-empaqueta.
- **Sin repositorios**: el servicio usa Eloquent directamente (lo estándar en
  Laravel). Solo se crea un repositorio, con interfaz e inyección, para consultas
  complejas reutilizadas por varios servicios.
- **Excepciones justificadas** (sin interfaz): `LogService` y `CacheService`
  (fachadas estáticas globales), `Services/Logging/*` (fachadas estáticas de
  registro), `Services/LLM/*` (el gateway es un constructor fluido con estado por
  llamada), `WebhookUrlGuard` (colaborador interno). Donde Laravel no permite
  inyectar (accessor de un modelo, `Auth::viaRequest`, `failed()` de un job, un
  `JsonResource`) se usa `app(Interfaz::class)`, nunca la clase concreta.
- **Octane**: prohibidas las propiedades estáticas mutables en `App\` (usa
  constantes, `config()`, atributos de la petición o un binding). Única excepción
  temporal: `Tenant::$currentTenant`.
- Lo comprueban las reglas de `tests/Arch/ArchTest.php`: los controllers solo
  importan `App\Contracts`, cada servicio con interfaz la implementa y no hay
  estáticos mutables.

**Una sola base de controlador**: `App\Http\Controllers\Controller` (abstracta,
como la de Laravel). Trae autorización, validación y los helpers de respuesta
(`sendSuccess`, `sendError`, `sendPaginated`) y **ninguna acción CRUD**: cada
controller declara las suyas con Form Requests tipados
(`store(StoreXRequest $request)`) y `findOrFail`/route-model binding. Un
parámetro sin tipo (`store($request)`) hace que Laravel no inyecte el Request y
la ruta dé 500. Los scopes del `Model` base están sin tipar: no estreches su
firma en los hijos. Detalle en `docs/CODING_STANDARDS.md`.

## Rutas — NO existe el prefijo /api/

Las rutas cuelgan de la raíz. Cada módulo vive en `routes/api/<modulo>.php` y se
incluye con `require` desde `routes/api.php`.

| Ámbito | Prefijo | Ejemplo |
|---|---|---|
| Público | ninguno | `GET /health` |
| Recurso autenticado | el del módulo | `GET /users`, `POST /files` |
| Perfil del usuario en sesión | `/user` | `GET /user/profile` |
| Administración | `/admin` | `GET /admin/logs` |

Middleware con alias disponibles: `auth:api`, `permission:`, `role:`,
`role_or_permission:`, `api-key-scope:`, `ip.whitelist`,
`feature:`, `auth.optional`.

### Permisos (spatie/laravel-permission, guard `api`)

- **Cada ruta declara su permiso exacto y el guard**: `->middleware('permission:users.read,api')`.
  No existe mapa método HTTP → acción. Varios (OR): `permission:a.read|b.read,api`.
- Nombre `recurso.accion` en minúsculas (`users.manage-roles`). Todo permiso nuevo va en
  `PermissionSeeder::catalog()`; `PermissionCatalogTest` falla si hay uno usado sin sembrar o sembrado sin uso.
- En código: `$user->can('x.y')` (respeta super admin), `checkPermissionTo()` (estricto), `hasRole()`,
  `assignRole()`, `givePermissionTo()`. Nunca `DB::table` sobre las tablas de roles/permisos (no refresca la caché).
- Super admin: el rol `admin` pasa todo vía `Gate::after` (las salvaguardas explícitas de las policies siguen mandando).
- Denegación → 403 RFC 7807 (`UnauthorizedException` renderizada en `bootstrap/app.php`); sin token → 401.
- Teams (multiempresa por `tenant_id`) preparado y apagado (`PERMISSION_TEAMS=false`). Detalle en `docs/permissions.md`.

## Autenticación

- **JWT** (`php-open-source-saver/jwt-auth`) → método principal de la API
- Sanctum **no** está instalado: no hay tokens personales de Sanctum, solo JWT y API Keys
- **API Keys** (guard `api-key`: `auth:api-key` + `api-key-scope:`; sin scopes = sin acceso) → integraciones máquina a máquina. Ver `docs/api-keys.md`, `docs/files.md` y `docs/webhooks.md`
- Rutas protegidas con `auth:api`; autenticación opcional con `auth.optional`
  (deja el usuario en `$request->attributes->get('auth_user')`)

## Base de datos

- PostgreSQL exclusivamente: nada de sintaxis MySQL (`jsonb`, `ILIKE`, etc.)
- Migraciones en `database/migrations/`. Columnas JSON siempre `$table->jsonb()` (nunca `json`); `JsonbColumnsTest` lo vigila. No reescribas migraciones ya aplicadas: crea una nueva.
- Los cambios que rompen proyectos derivados se anotan en `docs/upgrading.md`.
- `make schema-dump` → exporta el DDL a `database/schema.sql`
- `make backup` / `make restore-backup` / `make restore-remote` → ver `docs/backups-system.md`

## Testing

Guía completa: `docs/testing.md`.

- Pest 4 sobre PHPUnit. Tests en `tests/Unit/` y `tests/Feature/`; `tests/Integration/`
  (contra Mailpit y S3 reales, `make test-integration`) se omite sin servicios.
- **Antes de correr tests la primera vez**: `make testdb-migrate`. Los tests usan
  una BD aislada `<DB_DATABASE>_test` que nunca toca la de desarrollo
  (ver `docs/testing-database.md`).
- `make test` · `make test-unit` · `make test-feature` · `make test-arch` ·
  `make test-filter filter=NombreTest`
- Cobertura: `make test-coverage` (pcov, apagado por defecto).
- Mutación: `make test-mutate`. Paralelo: `make testdb-parallel` + `make test-parallel`.
- Nunca `php artisan test` en el host.
- Si la BD de tests queda con drift tras añadir migraciones: `make testdb-migrate`.
- Nombres de test en español y en tercera persona, describiendo la conducta.
  Cubre siempre el camino denegado, no solo el feliz.

## Respuestas API — usar los métodos del Controller base

- ✅ `sendSuccess($data, $message, $statusCode)` → 200, 201
- ✅ `sendError($message, $statusCode, $errors)` → 4xx, 5xx
- ✅ `sendPaginated($paginator)` → listados
- ❌ NUNCA `response()->json()` a mano en un controller: rompe el envelope
- Todo error lleva `code` (enum `App\Enums\ErrorCode`, o string propio vía
  `ApiException::errorCode()`) y `request_id` (= `X-Request-ID`): ver
  `docs/api-response-structure.md`. Un case nuevo del enum se documenta ahí.
- `App\Helpers\ApiResponse` sí se usa en middlewares, handlers y en el propio
  Controller base — ahí es la capa correcta.

## Entrada de datos — no se sanea en middleware

- La API guarda lo que recibe, validado con Form Requests; quien pinta HTML escapa (Blade, correos, frontend).
- ❌ No quitar etiquetas, colapsar espacios ni convertir tipos (`"1"`, `"no"`) a ciegas en middleware o Form Request.
- `TrimStrings` y `ConvertEmptyStringsToNull` (globales de Laravel) bastan; las contraseñas no se recortan.

## Manejo de errores

- Confía en el handler global de `bootstrap/app.php` (produce RFC 7807).
- `try-catch` solo cuando haya lógica específica que aportar.
- Registra con `LogService` en operaciones críticas.
- ❌ No verifiques existencia a mano con `if (! $model)`: usa `findOrFail()` y
  deja que la excepción llegue al handler.

## Correo — un solo camino

- Todo correo es un **Mailable** o una **Notification** de Laravel, en cola, enviado
  con `Mail::` / `->notify()`. Cuentas en BD, pausa, límites y `mail_logs` se
  aplican solos (transport `accounts` + listeners de `MessageSending`/`MessageSent`).
- Para poder pausarlo desde el panel, implementa `App\Contracts\Mail\HasMailTemplate`.
- ❌ No llames a `Mail::mailer('x')` a mano ni crees otro camino de envío.
- Detalle: `docs/mail-system.md`.

## Servicios de desarrollo (perfil `dev`)

- `make up-dev` añade **Mailpit** (bandeja de correo, `make mail-ui`) y un **S3 local**
  (RustFS; MinIO ya no publica imágenes) con buckets público y privado.
- `make s3-test` / `php artisan storage:check` prueban los discos de archivos.
- Imágenes de Compose con versión fija: nunca `latest`. Detalle: `docs/local-services.md`.

## Rendimiento — no paginar en memoria

- Listados **siempre** con `->paginate()` en SQL. Nunca `->get()` seguido de
  `forPage()` o `array_slice()`.
- Si un filtro u ordenamiento necesita cálculo en PHP, pagina primero en SQL y
  transforma **solo la página**.
- Objetivo: endpoints de listado < 500 ms con miles de filas.
- Eager loading obligatorio (`with`, `withCount`): ver la regla N+1 en
  `docs/CODING_STANDARDS.md`.

## Cache

- **Listados públicos**: se pueden cachear en Redis con TTL corto.
- **Endpoints de admin**: no cachear datos transaccionales; optimizar con
  paginación SQL y eliminación de N+1.
- Todo endpoint que modifique algo visible en un listado público cacheado debe
  invalidar esa clave.
- Ver `docs/cache-service-usage.md`, `docs/cache-invalidation.md` y
  `docs/cache-warming.md`.

## Logging

- `LogService` para logs de aplicación; canales `activity`, `security`, `auth`
  organizados por fecha.
- Los warnings de deprecación de `vendor/` se filtran automáticamente
  (`app/Logging/FiltersDeprecationWarnings.php`, configurable en
  `config/logging.php` → `deprecation_filter`).

## Normas

Una norma por documento; esos archivos son la fuente de verdad de cada tema:

- [`docs/modules.md`](docs/modules.md) → anatomía de un módulo, checklist «añadir un módulo» y qué es núcleo u opcional.
- [`docs/multi-tenancy.md`](docs/multi-tenancy.md) → multiempresa opt-in: `TenantContext`, `BelongsToTenant` fail-closed, middleware `tenant`, jobs y caché.
- [`docs/octane.md`](docs/octane.md) → código seguro con Octane/FrankenPHP (estado, bindings, guards, caché de permisos).
- [`docs/queues-and-jobs.md`](docs/queues-and-jobs.md) → colas, base `Job`, reintentos, idempotencia, `afterCommit`.
- [`docs/integrations.md`](docs/integrations.md) → integraciones externas con versión simulada y webhooks entrantes (pagos, WhatsApp, SMS, push…).
- [`docs/migrations.md`](docs/migrations.md) → migraciones inmutables, expandir/contraer, `jsonb`, estados, pruebas.
- [`docs/money.md`](docs/money.md) → dinero en unidades menores + moneda (norma para la fase de suscripciones).

## Al añadir algo al boilerplate

1. ¿Sirve a cualquier proyecto que parta de esta base? Si no, no va aquí.
2. ¿Está documentado en `docs/`?
3. ¿Tiene test?
4. ¿Pasa `make check`?
5. ¿Se configura por `config/` + `.env` en lugar de estar hardcodeado?

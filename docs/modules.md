# Módulos: cómo se organiza uno y cómo añadirlo

Un «módulo» en APYGG es una pieza vertical (ruta → controller → servicio → modelo → permisos →
tests) que se puede entender y, en los opcionales, quitar sin tocar el resto. Referencias
reales para imitar: **Archivos** (`files`), **Webhooks**, **Correo** (`mail`).

## Anatomía de un módulo (hoy)

| Capa | Dónde | Regla |
|---|---|---|
| Rutas | `routes/api/<modulo>.php`, incluido con `require` en `routes/api.php` | Sin prefijo `/api`; `docs/api-routes-conventions.md`. Cada ruta con `permission:x.y,api` (guard `api` explícito); `->name('modulo.recurso.accion')`, sin nombres duplicados |
| Controller | `app/Http/Controllers/<Modulo>/XController.php` | Extiende `Controller`, **solo importa `App\Contracts`** (ArchTest), respuestas con `sendSuccess`/`sendError`/`sendPaginated`, acciones tipadas (`store(StoreXRequest $request)`). `docs/CONTROLLER_CONVENTIONS.md` |
| Form Requests | `app/Http/Requests/<Modulo>/` | Heredan de `BaseFormRequest` (ArchTest) |
| Resource | `app/Http/Resources/<Modulo>/` | Heredan de `BaseResource`; nunca devuelvas el modelo crudo |
| Servicio | interfaz en `app/Contracts/XServiceInterface.php`, clase en `app/Services/XService.php`, `bind` en `ContractServiceProvider`, línea en `archServiciosConInterfaz()` | `docs/CODING_STANDARDS.md` → «Crear un servicio nuevo» |
| Modelo | `app/Models/X.php` (hereda de `App\Models\Model`) | Sin servicios ni capa HTTP (ArchTest) |
| Policy | `app/Policies/XPolicy.php` (`FilePolicy`, `UserPolicy`…) y `$this->authorize()` en el controller | Para reglas que dependen del recurso («¿es tuyo?»). El middleware `permission:` solo es la puerta de la ruta |
| Permisos | `PermissionSeeder::catalog()` (+ `RoleSeeder`) | Nombre `recurso.accion`; `PermissionCatalogTest` falla si usas un permiso sin sembrar o siembras uno sin uso. `docs/permissions.md` |
| Jobs | `app/Jobs/` extienden `Job` | `docs/queues-and-jobs.md` |
| Config | `config/<modulo>.php` + `.env.example` | Nunca `env()` en servicios/controllers |
| Tests | `tests/Feature/<Modulo>/` (+ `tests/Unit/`) | Nombres en español, tercera persona; **camino denegado obligatorio** (403/401/422), p. ej. `ArchivosTest` comprueba `assertForbidden()` con otro usuario |
| Docs | `docs/<modulo>.md` | Qué hace, endpoints, permisos, variables |
| Multiempresa | trait `BelongsToTenant`, middleware `tenant` | Si el módulo guarda datos de una empresa: `tenant_id` + trait + ruta con `tenant`. `docs/multi-tenancy.md` |

## Checklist: añadir un módulo nuevo

Antes: ¿sirve a cualquier proyecto que parta del boilerplate? Si es lógica de un negocio
concreto, **no va aquí** (`AGENTS.md`). Si es dinero o un servicio de terceros, lee antes
`docs/money.md` y `docs/integrations.md`.

1. **Migración** nueva (nunca editar las publicadas): `jsonb`, índices, `down()`, estados como
   string. `docs/migrations.md`. Luego `make testdb-migrate`.
2. **Modelo** hereda de `App\Models\Model`; `$fillable`, casts (`bigint` → `integer`), relaciones;
   scopes sin estrechar la firma del base.
3. **Interfaz + servicio + binding** (`bind`) + registro en `archServiciosConInterfaz()`.
   Errores de dominio con `BusinessLogicException`, nada de `\Exception`.
4. **Form Requests** (`Store…Request`, `Update…Request`) heredando de `BaseFormRequest`.
5. **Resource** que defina la forma pública (sin campos internos ni secretos).
6. **Controller** que inyecta la **interfaz**, tipa los Form Requests, usa `findOrFail`/route-model
   binding, `sendSuccess`/`sendPaginated` y `$this->authorize()` si hay regla por recurso. Listados
   con `->paginate()` y eager loading.
7. **Policy** si hay reglas por recurso (dueño, jerarquía).
8. **Permisos**: añádelos a `PermissionSeeder::catalog()` y al rol que corresponda en
   `RoleSeeder`; en entornos existentes `db:seed --class=PermissionSeeder`.
9. **Rutas**: `routes/api/<modulo>.php` con `auth:api` + `permission:x.y,api` y nombres; añade el
   `require` en `routes/api.php`.
10. **Cola/eventos** si aplica: job que extiende `Job`, cola declarada en Horizon, `afterCommit`.
11. **Cache**: si un listado público cacheado cambia, invalida su clave (`docs/cache-invalidation.md`).
12. **Config y `.env.example`** (y `docs/upgrading.md` si rompe proyectos derivados).
13. **Tests Feature** con camino feliz **y denegado** (sin token, sin permiso, de otro usuario);
    unitarios del servicio. Si hay concurrencia (contadores, numeraciones, idempotencia): un test
    de `tests/Concurrency/`. **Si el módulo tiene datos por empresa** (`BelongsToTenant` / `tenant_id`):
    prueba de aislamiento A/B en `tests/Feature/Tenancy/Aislamiento/` con la plantilla
    `tests/Support/AssertsTenantIsolation.php` y registra el modelo en `CoberturaDeAislamientoTest`
    (lo exige el meta-test); ver `docs/multi-tenancy.md` §«Cómo probar el aislamiento de un recurso nuevo».
14. **Documentación** `docs/<modulo>.md` y enlace desde `AGENTS.md` si es una norma.
15. **`make check`** (Pint + PHPStan nivel 6) y `make test`. Todo en Docker vía Make.

## Núcleo y módulos opcionales

**Núcleo (no se quita sin rehacer el boilerplate):** autenticación JWT y sesiones, usuarios,
roles y permisos (Spatie), tokens de API y API keys, archivos, ajustes, webhooks salientes,
correo (`mail`), notificaciones, logs/auditoría y seguridad, health checks, cola/Horizon, caché.

**Opcionales:** se pueden apagar o quitar. Lo que está comprobado en el repo:

| Módulo | Qué incluye | Apagarlo sin quitarlo | Quitarlo |
|---|---|---|---|
| **Back-office (Filament)** | `app/Filament/`, `BackofficePanelProvider`, `config/backoffice.php` | `BACKOFFICE_ENABLED=false` (el panel no se registra; `/backoffice` da 404) | Procedimiento completo y comprobado en `docs/backoffice.md` → «Quitar el módulo» |
| **LLM** | `app/Services/LLM/`, `routes/api/llm.php`, `config/llm.php`, tablas `llm_areas` y `logs_llm`, permisos `llm.manage`/`llm.logs` | Sin `LLM_URL`/`LLM_API_KEY` se usa `NullProvider` (respuesta «skipped», sin red); flags por caso de uso en `config/features.php` (`docs/llm.md`) | Ver nota 1 |
| **Docs RAG** | `app/Services/Docs/`, `routes/api/docs.php`, `docs:index`, tablas `doc_chunks` y `doc_conversations`; depende de LLM para responder | Sin LLM devuelve solo búsqueda (`docs/docs-assistant.md` → «Sin LLM configurado») | Ver nota 1 |
| **Búsqueda (Scout/Meilisearch)** | `app/Traits/Searchable.php`, `routes/api/search.php`, `config/scout.php`, servicio `meilisearch` | `SCOUT_DRIVER=database`; `AppServiceProvider` ya cae solo a `database` si Meilisearch no responde | Ver nota 1 |
| **Reverb (WebSockets)** | `laravel/reverb`, `config/reverb.php`, `routes/channels.php`, servicio `reverb` | `BROADCAST_CONNECTION=null` (la raíz `/` ya omite la sección `websockets` si es `null`) | Ver nota 1 |

**Nota 1 — quitar un opcional.** Solo el back-office tiene un procedimiento completo y
verificado. Para los demás, el orden seguro es el mismo, pero **no está comprobado paso a paso**
en el repo, hazlo en una rama y deja que `make check` y los tests te lo confirmen:

1. Quita el `require` de su archivo en `routes/api.php`.
2. Borra controllers, requests, resources, servicios, contratos, modelos y `config/<modulo>.php`;
   quita su binding de `ContractServiceProvider` y su línea de `archServiciosConInterfaz()`.
3. Quita sus permisos de `PermissionSeeder::catalog()` y de `RoleSeeder`, y bórralos en los
   entornos existentes (`PermissionCatalogTest` lo exige); `permission:cache-reset`.
4. Quita sus tests y su doc. Quita variables de `.env.example` y `phpunit.xml`.
5. `composer remove <paquete>` si el paquete ya no lo usa nadie (`laravel/reverb`,
   `meilisearch/meilisearch-php`, `laravel/scout`…); comprueba referencias residuales en
   `User`, el menú (`MenuService`) y `docker-compose.yml`.
6. **Las migraciones ya publicadas no se borran** (`docs/migrations.md`): crea una migración que
   elimine sus tablas, o déjalas inertes. Un proyecto nuevo puede omitirlas antes del primer despliegue.
7. `make check` y `make test`.

Un módulo opcional nuevo debe cumplir: todo bajo su propia carpeta, un único interruptor en
`.env`, sin que el núcleo lo importe (dependencia solo hacia el núcleo), y su sección «Quitar el
módulo» en su doc.

# Multiempresa (multi-tenancy)

Un mismo despliegue sirve a varias empresas (tenants) con los datos aislados. Es **opt-in**:
con `MULTI_TENANCY_ENABLED=false` (por defecto) nada de esto actúa y el proyecto se comporta
como single-tenant. Diseño: base de datos única, columna `tenant_id`, aislamiento **fail-closed**
(sin tenant en el contexto, un modelo con tenant lanza error en vez de devolver todo).

> Los recursos del boilerplate que pertenecen a una empresa ya están aislados (§ «Qué es por tenant»)
> y los equipos de Spatie están implementados y se activan aparte (`docs/permissions.md` §Teams).

## Piezas

| Pieza | Dónde |
|---|---|
| Tenant activo (por petición/job) | `App\Support\Tenancy\TenantContext` (`scoped`, nunca estático) |
| Modelos | `Tenant` (`status`, `parent_id`, `settings`), tabla pivote `tenant_user` |
| Aislamiento | trait `App\Traits\BelongsToTenant` + `App\Scopes\TenantScope` |
| Resolución y acceso | middleware `tenant` (`App\Http\Middleware\ResolveTenant`) |
| Escape de plataforma | `App\Support\Tenancy\TenantQuery::withoutTenant()` |
| Jobs | `Context` de Laravel + listener `RestoreTenantForJob` |
| Excepciones | `MissingTenantContext` (400), `CrossTenantViolation` (403), `TenantAccessDenied` (403), `TenantSuspended` (403) |

## Qué es por tenant (y por qué)

| Recurso | Ámbito | Por qué |
|---|---|---|
| Archivos (`files`) | **tenant** | Contenido de la empresa; la clave de disco es `tenants/<id>/...` |
| Webhooks y entregas (`webhooks`, `webhook_deliveries`) | **tenant** | Apuntan a sistemas de la empresa y reciben solo sus eventos |
| API keys (`api_keys`) | **tenant** | Una key pertenece a un tenant y FIJA su contexto al autenticar |
| Logs de actividad/API/seguridad | **registran** `tenant_id`, NO se aíslan | Se filtran por tenant (`?tenant_id=` en `/admin/logs/*`) pero la plataforma los ve todos |
| Usuarios, `tenant_user` | **global** | Una persona puede estar en varios tenants; el acceso lo da la membresía |
| Roles y permisos | **global** + asignación por tenant (Spatie teams, opt-in) | Ver `docs/permissions.md` §Teams |
| Settings (`settings`) | **plataforma** | `key` es única global y son ajustes del sistema; la configuración de una empresa va en `tenants.settings` |
| Correo, LLM, docs, menú, lista negra, escáner de seguridad | **plataforma** | Infraestructura compartida; si tu proyecto necesita cuentas de correo o cuotas LLM por empresa, añade `BelongsToTenant` ahí |

Las columnas `tenant_id` de `files`, `webhooks`, `webhook_deliveries` y `api_keys` son **NULLABLE**
(migraciones de creación de cada tabla) para no romper instalaciones sin multiempresa. **Con multiempresa activo los
registros nuevos siempre la llevan** (`BelongsToTenant` la rellena o falla). Las filas anteriores (NULL) quedan
invisibles para todos los tenants hasta asignarlas: `php artisan tenancy:assign-orphans <tenant> [--dry-run]`.
Los logs (`000061`) llevan la columna con índice y sin FK (solo-añadir, alto volumen).

### Cómo se comporta cada recurso

- **Rutas**: `files`, `webhooks` y `api-keys` van con `['auth:api', 'tenant']`. Con multiempresa apagado el
  middleware no hace nada. Sin `X-Tenant` responden 400 `tenant_required`. La ruta firmada
  `/files/{id}/signed` y la de API key (`auth:api-key`) no usan `tenant` (ver abajo).
- **Archivos**: la clave de almacenamiento incluye el tenant (`FileService::tenantStoragePath`). La URL firmada
  lleva `tenant=<id>` **dentro de la firma**: la descarga no tiene sesión, así que el contexto sale de ahí; si la
  petición declara otro tenant (cabecera `X-Tenant`) responde 403, y un archivo de otro tenant no se encuentra
  (404). `streamDownload` además rechaza un archivo cuya ruta `tenants/<id>/` no coincide con su `tenant_id`.
  Los archivos anteriores a la activación conservan su ruta antigua.
- **Webhooks**: `WebhookListener` solo entrega si hay tenant en el contexto (el listener en cola hereda el del
  evento vía `Context`); un evento sin tenant (login, tareas de plataforma) no se entrega a nadie. `SendWebhookJob`
  guarda el `tenant_id` con el que se encoló, lo restaura en el worker y falla si el contexto es otro o no hay
  tenant (`failed()` también corre en su tenant). Un webhook de A nunca recibe eventos de B.
- **API keys**: `ApiKeyService::authenticate` busca la key sin filtro de tenant (consulta de plataforma, sin
  auditar por petición: ya deja `logApiKeyUsage`), exige que la key tenga un tenant `active` y que su usuario siga
  pudiendo entrar (miembro activo o `tenants.access-any`) y fija el `TenantContext` (se limpia al terminar la
  petición). La cabecera `X-Tenant` se **ignora** con `auth:api-key`. La caché de validación de keys es global
  (el hash es único) y se invalida igual desde cualquier tenant. `php artisan api-key:create` exige `--tenant=`.
- **Comandos**: `webhooks:cleanup-old-secrets` y la auditoría de seguridad usan `TenantQuery::withoutTenant`;
  `cache:warm` calienta tenant por tenant.
- **Logs**: el trait `RecordsTenant` rellena `tenant_id` si hay tenant (sin él, NULL). No falla nunca por falta de tenant.

## Activarlo

1. `.env`: `MULTI_TENANCY_ENABLED=true` y, según cómo identifiques el tenant, `TENANT_RESOLUTION`
   (`header,route,subdomain,domain`, en orden), `TENANT_HEADER` (por defecto `X-Tenant`),
   `TENANT_BASE_DOMAIN` (para subdominios). Todo en `config/multi-tenancy.php`.
2. Migraciones: `tenants` (con `status` y `parent_id`) y `tenant_user` ya vienen en el boilerplate.
3. Crea tenants y membresías: por alta libre (`POST /tenants`) e invitaciones (`docs/tenant-onboarding.md`) o a mano
   (`$tenant->users()->attach($user, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()])`).
4. Seeder de permisos: `tenants.access-any` (solo para soporte de plataforma).

## Hacer un modelo multiempresa

```php
// migración nueva: la columna con FK y su índice
$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

// modelo
class Invoice extends Model { use BelongsToTenant; }
```

Con multi-tenancy activo:

- **Leer**: el scope añade `where tenant_id = <activo>`. Sin tenant en el contexto lanza `MissingTenantContext`.
- **Crear**: rellena `tenant_id` con el del contexto; uno distinto lanza `CrossTenantViolation`.
- **Actualizar/borrar/restaurar/hidratar**: se comprueba que el registro es del tenant activo
  (cubre `withoutGlobalScopes()` y la restauración de modelos serializados en jobs).
- **Cambiar `tenant_id`** de un registro existente: siempre `CrossTenantViolation`, también en plataforma.
- Las claves únicas deben incluir `tenant_id` (`unique(tenant_id, slug)`), no ser globales.

## Resolver el tenant en una ruta

```php
Route::middleware(['auth:api', 'tenant'])->group(function () { ... });      // exige tenant
Route::middleware(['auth:api', 'tenant:optional'])->get(...);               // lo admite
```

Va **después** de `auth:api`. Estrategias (config `resolution.strategies`): cabecera `X-Tenant`
(id o slug), parámetro de ruta `{tenant}`, subdominio `<slug>.<TENANT_BASE_DOMAIN>`, dominio propio
(`tenants.domain`). Se comprueba que el usuario es **miembro activo** o tiene `tenants.access-any`.

| Caso | Respuesta |
|---|---|
| Sin tenant y la ruta lo exige | 400 `tenant_required` |
| Tenant inexistente o usuario sin membresía activa | 403 `code` `tenant_invalid` (`type` `tenant_forbidden`; misma respuesta: no revela qué tenants existen) |
| Tenant no `active` (también para plataforma) | 403 `tenant_suspended` |
| Sin autenticar | 401 (lo da `auth:api`) |

El middleware limpia el contexto al terminar la petición. Con multi-tenancy apagado no hace nada.

## Membresías

`tenant_user (tenant_id, user_id, status, role, invited_by, joined_at)`, único por par (`role` es el rol del tenant:
`owner`/`admin`/`member`; ver `docs/tenant-onboarding.md`). Una persona puede
estar en varios tenants; solo `status = active` da acceso (`invited` y `suspended` no).
`User::tenants()`, `Tenant::users()`, `User::isActiveMemberOf($tenant)`. `Tenant::parent()/children()`
modelan una jerarquía opcional (agencia → negocio) **solo como dato**: no concede acceso heredado;
si lo quieres, impleméntalo en tu proyecto de forma explícita.

## Código fuera de una petición (comandos, jobs de plataforma)

```php
app(TenantContext::class)->run($tenant, function () { ... });   // filtra por ese tenant y restaura al salir
TenantQuery::withoutTenant(fn () => Invoice::count(), 'informe mensual');  // TODOS los tenants; deja rastro en el log de seguridad
```

`withoutTenant` es la **única** vía para saltarse el aislamiento; `ArchTest` prohíbe
`withoutGlobalScope(TenantScope)` fuera de `app/Support/Tenancy`. Úsalo con un motivo, solo en tareas de
plataforma, y prefiere `run()` siempre que sepas el tenant.

## Jobs (colas)

Al fijar el tenant, `TenantContext` escribe `tenant_id` en el `Context` oculto de Laravel
(`Context::addHidden`). Laravel lo serializa en el payload de cada job encolado y lo hidrata en el
worker; `RestoreTenantForJob` (JobProcessing) fija el `TenantContext` y lo limpia al terminar
(`JobProcessed/JobFailed/JobExceptionOccurred`). Un job encolado **sin** tenant corre sin contexto y falla
cerrado si toca un modelo con tenant; si el tenant ya no existe, el job falla. **Tenants no activos:** si el tenant está `suspended` o `pending`, `RestoreTenantForJob` lanza `TenantInactiveForJob` (403 `tenant_inactive`) y el job **no se ejecuta**: queda en `failed_jobs` y se reencola con `queue:retry` al reactivar el tenant. `SendWebhookJob` además descarta la entrega con un log `warning` si llega a ejecutarse con un tenant no activo (sin enviar nada a su cuenta). La conexión `sync` corre
en la petición y conserva su contexto. Para encolar desde un comando: `->run($tenant, function () { Job::dispatch(); })`. **No devuelvas** el `PendingDispatch` desde el closure (`fn () => Job::dispatch()`): se encola al destruirse, ya fuera del tenant.

## Octane

`TenantContext` es `scoped` (una instancia por petición/job) y el `Context` de Laravel también;
además `ResetTenantContext` se ejecuta en `RequestReceived/TaskReceived/TickReceived`. No guardes el tenant
en propiedades estáticas ni en singletons (`docs/octane.md`).

## Caché

`CacheService` prefija sus claves con `t<id>:` cuando multi-tenancy está activo y hay tenant (`get/set/remember/
forget/has` y las búsquedas). Con la fachada `Cache` directa, prefija tú: `Cache::get("t{$ctx->id()}:informe")`.
`rememberUser`/`rememberEntity` no se prefijan (el usuario y las entidades globales no pertenecen a un tenant).
Con `forgetPattern` incluye el segmento `t<id>:` si quieres limitar al tenant.

## Cómo probar el aislamiento de un recurso nuevo

Todo recurso multiempresa (modelo con `BelongsToTenant`, o tabla nueva con `tenant_id`) lleva su prueba en
`tests/Feature/Tenancy/Aislamiento/<Recurso>AislamientoTest.php`. La plantilla es el trait
[`tests/Support/AssertsTenantIsolation.php`](../tests/Support/AssertsTenantIsolation.php) (cópiate un archivo de ese
directorio, p. ej. `WebhooksAislamientoTest.php`):

```php
uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);
beforeEach(fn () => $this->bootIsolation());   // multiempresa activa, tenants $this->a y $this->b, $this->ctx
```

Cuatro casos obligatorios, con datos en A **y** en B:

| Caso | Qué se comprueba | Ayuda |
|---|---|---|
| (a) Listado | desde A solo salen filas de A | `assertListIsolated()` |
| (b) Id ajeno | ver/editar/borrar/acciones sobre un id de B desde A dan **404** (nunca 403: no confirma que exista) y la fila de B sigue intacta | `assertForeignIdNotFound()` |
| (c) `tenant_id` del cuerpo | crear desde A con `tenant_id` de B queda en A | `assertBodyTenantIgnored()` |
| (d) Modelo/consulta | dentro de `run(A)` no aparecen filas de B y sin contexto (multiempresa activa) lanza `MissingTenantContext` | `assertModelIsolated()` |

Además: un usuario de A que declara `X-Tenant` de B recibe 403 (`ResolveTenant`: misma respuesta que un tenant
inexistente; es el contrato, no una fuga). Si el recurso tiene jobs, caché o contadores, mira
`JobsYCacheAislamientoTest` y `SecuenciasAislamientoTest`.

**Meta-test** (`CoberturaDeAislamientoTest`): recorre `app/Models` y falla si un modelo usa `BelongsToTenant` sin
estar en `AISLAMIENTO_MODELOS_CUBIERTOS`, o si una tabla tiene `tenant_id` sin estar cubierta
(`AISLAMIENTO_TABLAS_CUBIERTAS`). Un modelo nuevo obliga a añadir su prueba y registrarla ahí.

## Búsqueda (Scout / Meilisearch)

Un modelo que usa `Searchable` **y** `BelongsToTenant` indexa `tenant_id` (`toSearchableArray()`), lo declara
filtrable (`getFilterableAttributes()`) y `Model::search()` añade `where tenant_id = <contexto>` solo: la
búsqueda no cruza empresas. Falla cerrado (`MissingTenantContext`) sin tenant en el contexto; dentro de
`TenantQuery::withoutTenant()` (plataforma) no filtra. Un modelo que sobrescriba `toSearchableArray()` o
`getFilterableAttributes()` **debe incluir `tenant_id`**. Con el motor `database` de Scout el filtro es una
cláusula `where` sobre la columna. Los modelos sin tenant (p. ej. `User`) no cambian. Tras activar
multiempresa en una instalación con índice previo: `scout:flush` + `scout:import` (el índice antiguo no
trae `tenant_id`).

## Huecos conocidos (léelos antes de activarlo)

- `/users`, `/roles`, `/admin/*` son **de plataforma**: no usan el middleware `tenant`. Un usuario con
  `users.read` ve a todos los usuarios. La administración *dentro* de un tenant (miembros, roles del tenant,
  invitaciones, alta de empresa) ya existe en `/tenant/members`, `/tenant/invitations` y `/tenants`:
  ver `docs/tenant-onboarding.md`. No des permisos de plataforma a roles de cliente.
- Un evento fuera de tenant no llega a webhooks (decisión de seguridad, no un fallo).
- Las filas con `tenant_id` NULL son invisibles para los tenants pero visibles en `withoutTenant()`.
- Los archivos antiguos conservan su ruta sin `tenants/<id>/`.
- `DB::table(...)` y las operaciones masivas no pasan por el scope (ver abajo).

## Qué NO hacer

- No uses `Model::query()->update(['tenant_id' => ...])`, `insert()` ni `upsert()` con `tenant_id`: las
  operaciones masivas no pasan por los hooks del modelo (el scope de lectura sí filtra).
- No uses `exists:`/`unique:` (ni `Rule::exists`/`Rule::unique`) sobre una tabla con `tenant_id` sin acotarla:
  consultan TODAS las empresas y responden «ya existe» / «existe» para datos de otro tenant (oráculo entre
  tenants) y producen colisiones falsas de unicidad. Usa `App\Rules\TenantExists` y `App\Rules\TenantUnique`
  (añaden `where tenant_id = <contexto>`; combínalas con un índice único `(tenant_id, col)`).
- No uses `withoutGlobalScopes()` (quita también el scope de tenant): `ArchTest` lo prohíbe fuera de
  `app/Support/Tenancy` salvo `withoutGlobalScopes([SoftDeletingScope::class])`. Usa `TenantQuery::withoutTenant()`.
- No numeres para otro tenant con `Sequence::next($name, $otroTenantId)` dentro de un tenant: con aislamiento
  activo lanza `CrossTenantViolation`; solo vale dentro de `withoutTenant()`.
- No guardes el tenant en estáticos, singletons ni propiedades de servicios.
- No uses `DB::table('tabla_con_tenant')` ni query builder crudo sin `where tenant_id`: no hay scope.
- No des `tenants.access-any` a roles de cliente; es para soporte de plataforma.
- No pongas rutas de plataforma con `withoutTenant` sin permiso explícito en la ruta.
- No confíes en el `tenant_id` que llegue en el cuerpo de una petición: lo fija el contexto.
- No asumas que un tenant padre ve a sus hijos.

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
(migración `2026_11_19_000060`) para no romper instalaciones sin multiempresa. **Con multiempresa activo los
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
3. Crea tenants y membresías (`$tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()])`).
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
| Tenant inexistente o usuario sin membresía activa | 403 `tenant_forbidden` (misma respuesta: no revela qué tenants existen) |
| Tenant no `active` (también para plataforma) | 403 `tenant_suspended` |
| Sin autenticar | 401 (lo da `auth:api`) |

El middleware limpia el contexto al terminar la petición. Con multi-tenancy apagado no hace nada.

## Membresías

`tenant_user (tenant_id, user_id, status, invited_by, joined_at)`, único por par. Una persona puede
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
cerrado si toca un modelo con tenant; si el tenant ya no existe, el job falla. La conexión `sync` corre
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

## Huecos conocidos (léelos antes de activarlo)

- `/users`, `/roles`, `/admin/*` son **de plataforma**: no usan el middleware `tenant`. Un usuario con
  `users.read` ve a todos los usuarios; la administración de usuarios *dentro* de un tenant requiere un módulo
  propio (filtrando por `tenant_user`). No des permisos de plataforma a roles de cliente.
- Un evento fuera de tenant no llega a webhooks (decisión de seguridad, no un fallo).
- Las filas con `tenant_id` NULL son invisibles para los tenants pero visibles en `withoutTenant()`.
- Los archivos antiguos conservan su ruta sin `tenants/<id>/`.
- `DB::table(...)` y las operaciones masivas no pasan por el scope (ver abajo).

## Qué NO hacer

- No uses `Model::query()->update(['tenant_id' => ...])`, `insert()` ni `upsert()` con `tenant_id`: las
  operaciones masivas no pasan por los hooks del modelo (el scope de lectura sí filtra).
- No guardes el tenant en estáticos, singletons ni propiedades de servicios.
- No uses `DB::table('tabla_con_tenant')` ni query builder crudo sin `where tenant_id`: no hay scope.
- No des `tenants.access-any` a roles de cliente; es para soporte de plataforma.
- No pongas rutas de plataforma con `withoutTenant` sin permiso explícito en la ruta.
- No confíes en el `tenant_id` que llegue en el cuerpo de una petición: lo fija el contexto.
- No asumas que un tenant padre ve a sus hijos.

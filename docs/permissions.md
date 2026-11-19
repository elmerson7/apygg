# Roles y permisos (RBAC)

El control de acceso usa **spatie/laravel-permission 8.5**, con el guard `api` (JWT).
`App\Models\Role` y `App\Models\Permission` extienden los del paquete (conservan
`display_name`, `description`, `resource`, `action`, borrado lógico y el scope
`search()`); `User` usa el trait `HasRoles`.

## Declarar un permiso

1. Nombre `recurso.accion`, minúsculas: `users.read`, `reports.export`, `users.manage-roles`
   (el recurso en plural; la acción puede llevar guion).
2. Añádelo a `PermissionSeeder::catalog()` (y al rol que corresponda en `RoleSeeder`).
3. Úsalo en la ruta o la policy.
4. `make art cmd="db:seed --class=PermissionSeeder"` en los entornos existentes.

`tests/Feature/Authorization/PermissionCatalogTest.php` compara el catálogo con lo que
usan las rutas (`permission:`), las policies/controllers/servicios (`can('…')`) y el
menú: ni permisos usados sin sembrar, ni sembrados que nadie use.

### Convención de nombres

| Acción | Significado |
|---|---|
| `read` | listar y ver detalle |
| `create` / `update` / `delete` / `restore` | la operación homónima |
| `manage-<algo>` | gestionar una relación o un ámbito (`users.manage-roles`, `roles.manage-permissions`, `mail.manage`) |
| `logs`, `logs-delete`, `audit`… | acciones específicas del módulo |

No hay mapa mágico método HTTP → acción: **cada ruta declara su permiso exacto**.

## Proteger rutas

```php
Route::middleware(['auth:api'])->prefix('reports')->group(function () {
    Route::get('/', [ReportController::class, 'index'])
        ->middleware('permission:reports.read,api');

    Route::delete('/{id}', [ReportController::class, 'destroy'])
        ->middleware('permission:reports.delete,api');

    // Cualquiera de varios (OR): separados por |
    Route::get('/export', [ReportController::class, 'export'])
        ->middleware('permission:reports.export|reports.admin,api');

    // Por rol, o rol O permiso
    Route::get('/audit', [ReportController::class, 'audit'])->middleware('role:admin|manager,api');
    Route::get('/mine', [ReportController::class, 'mine'])->middleware('role_or_permission:manager|reports.read,api');
});
```

- **Siempre con el guard `api` como último parámetro.** Los middlewares de Spatie usan
  `Auth::guard($guard)`; sin guard toman el guard por defecto (`web`), que no autentica
  JWT y bajo Octane puede variar entre workers. El test lo exige en todas las rutas.
- `auth:api` va antes: sin token responde 401; con token y sin permiso, 403.
- Si el middleware deniega, `bootstrap/app.php` renderiza la `UnauthorizedException` de
  Spatie con el formato RFC 7807 del proyecto (`type: forbidden`, `required_permissions`)
  y deja rastro en el log de seguridad.
- Para reglas que dependen del recurso (¿es tuyo? ¿es admin?) usa una **policy** y
  `$this->authorize('update', $model)` en el controller; el middleware es solo la puerta de la ruta.

### En código

```php
$user->can('users.read');              // vía Gate: admin pasa siempre (ver más abajo)
$user->hasPermissionTo('users.read');  // estricto; lanza PermissionDoesNotExist si no existe
$user->checkPermissionTo('users.read');// estricto, sin lanzar (false si no existe)
$user->hasRole('admin');  $user->hasAnyRole(['admin', 'manager']);
$user->assignRole('manager');  $user->syncRoles($ids);  $user->removeRole($role);
$user->givePermissionTo('users.delete');  $user->revokePermissionTo(...);  $user->syncPermissions(...);
$role->givePermissionTo(...);  $role->syncPermissions(...);  $user->getAllPermissions();
```

Para código nuevo prefiere `can()` en policies/recursos (respeta el super admin) y
`checkPermissionTo()` cuando necesites comprobar que alguien *realmente tiene* el permiso
(p. ej. "no concedas permisos que no tienes", en `RolePolicy` y `RoleController`).
El método propio `hasPermission()` de la versión anterior ya no existe (usa `can()` o `checkPermissionTo()`;
`hasRole`, `hasAnyRole` y `hasAllPermissions` los aporta Spatie). `User::isAdmin()` es solo un atajo de `hasRole('admin')`.

## Super admin

El rol `admin` pasa cualquier comprobación de permiso aunque no lo tenga asignado ni exista:
`Gate::after` en `AuthServiceProvider`. Es `after` y no `before` (que es lo que muestra la doc
de Spatie) **a propósito**: `after` solo actúa si nadie ha decidido todavía. Así las
salvaguardas explícitas de las policies siguen mandando también sobre el admin (no borrarse a sí
mismo, no borrar el rol `admin`, no renombrarlo...). Con `before` se las saltaría.

Consecuencias: un permiso nuevo no hace falta asignárselo a `admin`; `RoleSeeder` se lo da igualmente
para que la UI de roles lo muestre.

### Jerarquía (se mantiene de la versión anterior)

Solo un admin edita, borra o cambia los roles de otro admin; nadie asigna un rol con permisos que
no tiene; nadie concede permisos que no tiene (`roles.manage-permissions` aparte de `roles.update`);
no se puede dejar el sistema sin admins ni borrar/renombrar el rol `admin`. Viven en `UserPolicy`,
`RolePolicy`, `UserService` y `RoleService`; tests en `tests/Feature/Authorization/`.

## Caché y Octane

Spatie guarda todos los permisos en caché (`spatie.permission.cache`, 24 h) y los refresca
solo al cambiar roles/permisos con su API o con los modelos (eventos de modelo).

- **Store**: `config('permission.cache.store')` = `default` → el `CACHE_STORE` de la app
  (Redis en producción; `array` en `phpunit.xml`, así los tests no dependen de Redis).
  Se puede fijar otro con `PERMISSION_CACHE_STORE`.
- **Octane**: el `PermissionRegistrar` es un singleton que además guarda los permisos en la memoria del
  worker; sin ayuda, un cambio hecho desde otro worker no se vería hasta reiniciarlo. Por eso
  `register_octane_reset_listener` está **activo** (`PERMISSION_OCTANE_RESET=true`): al terminar cada
  petición se vacía la copia en memoria y la siguiente relee de Redis (una lectura barata).
- Los seeders corren sin eventos de modelo y llaman a `forgetCachedPermissions()` al terminar.
  Si cambias tablas con SQL a mano: `make art cmd="permission:cache-reset"`.
- No cambies roles/permisos con `DB::table(...)`: no refresca la caché. Usa la API de Spatie.

## Esquema y migraciones

Tablas (nombres de `config/permission.php`): `roles`, `permissions` (con `guard_name`,
único `name + guard_name`), `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

Las migraciones originales no se tocaron. Dos nuevas llevan el esquema propio al de Spatie:

1. `2026_11_19_000002_adapt_rbac_tables_for_spatie_permission`: añade `guard_name` (los datos existentes
   quedan en `api`), cambia la unicidad, crea las tres tablas y **copia** `user_role` → `model_has_roles`,
   `user_permission` → `model_has_permissions` y `role_permission` → `role_has_permissions`.
2. `2026_11_19_000003_drop_legacy_rbac_pivot_tables`: borra las tres pivotes antiguas. Su `down()` las
   recrea y copia los datos de vuelta.

Antes de desplegar en un proyecto con datos: **haz backup** (`make backup`) y revisa la sección de riesgos
del PR. Después de migrar: `make art cmd="permission:cache-reset"`.

## Teams (multiempresa): implementado, apagado por defecto

Con `PERMISSION_TEAMS=true` los roles y permisos directos son **por tenant** (equipo de Spatie =
tenant; `team_foreign_key = tenant_id`). Con él apagado (por defecto) no hay columnas de más y nada cambia.

### Modelo

- **Equipo = tenant.** Una asignación (`model_has_roles`, `model_has_permissions`) lleva el `tenant_id` en el que vale:
  ser `admin` en A no lo es en B.
- **Equipo 0 = plataforma** (`PermissionTeamsSchema::PLATFORM_TEAM`). Es el equipo cuando no hay tenant activo
  (login, `/users`, `/admin/*`, comandos). Un *admin de plataforma* es quien tiene el rol `admin` asignado
  en el equipo 0 (`User::isPlatformAdmin()`); **es super admin en todos los tenants** (`User::isAdmin()` = admin
  del tenant activo **o** admin de plataforma, así lo usa el `Gate::after`).
- **`roles.tenant_id` NULL = rol global** (catálogo común: `admin`, `user`...), con valor = rol propio de ese tenant.
  Spatie solo deja asignar roles globales o del equipo activo. Un rol creado sin tenant activo es global; con
  tenant activo es de ese tenant (`Role::create`). La unicidad `(tenant_id, name, guard_name)` es
  `NULLS NOT DISTINCT`: dos roles globales no pueden llamarse igual.
- **Un tenant no modifica roles globales ni de otro tenant** (`RolePolicy` vía `Role::isOutOfScope()`): cambiarían
  para todos. Solo la plataforma. `Role::visibleInScope()` lista los visibles desde el ámbito actual (no es un scope
  global a propósito: la caché de permisos de Spatie hace `Permission::with('roles')` y un scope dependiente
  del tenant la corrompería).
- **`tenants.access-any`** se evalúa en el equipo de la plataforma: concedido solo dentro de A no permite entrar a B.
- El `TenantContext` sincroniza el equipo (`set()` → tenant, `clear()` → plataforma), también en jobs
  (`RestoreTenantForJob`), en `run()` y en el listener de Octane (`ResetTenantContext` lo reinicia por petición:
  el `PermissionRegistrar` es un singleton y sobrevive entre peticiones). `ResolveTenant` y la API key sueltan las
  relaciones `roles`/`permissions` cacheadas del usuario al cambiar de equipo (Spatie las cachea en el modelo).
- Las rutas `/users` y `/roles` siguen siendo de plataforma: asignan en el equipo 0. Asignar roles dentro de un
  tenant se hace en código con `TenantContext::run($tenant, fn () => $user->assignRole('gerente'))` o con un
  módulo propio de administración por tenant (no incluido).

### Activarlo en un proyecto

1. **Backup** (`make backup`).
2. `make art cmd="tenancy:enable-teams --assign-to=<tenant>"` (misma lógica que la guía oficial de Spatie
   «add teams fields», con las diferencias de arriba). Añade `tenant_id` nullable a `roles` y `tenant_id` NOT NULL
   a las dos pivotes **rehaciendo sus claves primarias**; las asignaciones existentes pasan al tenant indicado
   (sin `--assign-to`, a la plataforma) y los `admin` existentes además quedan como admin de plataforma.
   Es idempotente. Si el proyecto migra desde cero con `PERMISSION_TEAMS=true`, la migración `…000062` hace lo
   mismo sola (con `DEFAULT_TENANT_ID` como tenant de las asignaciones, si existe).
3. `PERMISSION_TEAMS=true` en `.env` y `make art cmd="permission:cache-reset"`. **Reinicia Octane y Horizon
   obligatoriamente**: Eloquent cachea por proceso las columnas de cada tabla (`$guarded`) y un worker que ya
   cargó `roles` sin `tenant_id` lo descartaría en silencio al crear roles hasta reiniciarse.
4. Revisa que cada usuario tenga sus roles en los tenants donde opera (sus asignaciones antiguas quedaron en
   un solo tenant o en la plataforma).
5. Reversible: `tenancy:enable-teams --revert` (**destructivo**: borra los roles propios de cada tenant y deja una
   asignación por rol).

Qué cubren las pruebas: `tests/Feature/Tenancy/SpatieTeamsTest.php` (aplican el esquema dentro de la transacción
del test; el DDL de PostgreSQL es transaccional).

Límites: no se probó con Octane real ni con carga concurrente; con teams activo cada comprobación de
`isAdmin()` en un tenant hace una consulta extra (memoizada por instancia de usuario).

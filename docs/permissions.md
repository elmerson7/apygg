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

## Teams (multiempresa): preparado, apagado

`config/permission.php` ya tiene `team_foreign_key = tenant_id` y `PERMISSION_TEAMS=false`. Con teams
apagado el esquema es el estándar y no hay columnas de más.

**Decisión: no se añade `tenant_id` a las pivotes ahora.** En Spatie, cuando teams está encendido,
`model_has_roles` y `model_has_permissions` llevan `tenant_id` **NOT NULL** y como parte de la clave
primaria; no puede ser nullable. Tenerla vacía "por si acaso" obligaría a inventar un tenant por defecto y
a rehacer claves de todos modos. Lo que sí admite un nulo es `roles.tenant_id` (rol global vs. rol de un
tenant) y eso lo crea la migración de activación.

Para activarlo en un proyecto (con el paquete ya en la versión del boilerplate):

1. `PERMISSION_TEAMS=true` en `.env`.
2. `make art cmd="permission:setup-teams"` genera la migración (`add_teams_fields`) con `tenant_id`: añade
   `roles.tenant_id` (nullable, con índice y único `tenant_id+name+guard_name`) y `tenant_id` en las dos
   pivotes (valor por defecto 1 para lo existente: ajústalo al tenant por defecto real
   antes de migrar) rehaciendo sus claves primarias.
3. `make art cmd="migrate"` y `make art cmd="permission:cache-reset"`.
4. Fijar el tenant de la petición **después** de autenticar: un middleware (tras `ResolveTenant`/`auth:api`)
   que haga `setPermissionsTeamId($tenant->id)` (helper global de Spatie). Con Octane el listener del
   paquete lo reinicia al acabar cada petición.
5. Revisar los chequeos que asumen roles globales: `User::isAdmin()` y el Gate del super admin usarían
   el rol del tenant actual; decide si el super admin es un rol global (`tenant_id` null).

Si el proyecto activa teams desde el primer `migrate` (con `PERMISSION_TEAMS=true` ya en el `.env`),
la migración `…000002` crea el esquema con `tenant_id` directamente.

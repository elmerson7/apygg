# Guía de Seeders

Los seeders pueblan la base con los datos mínimos del RBAC y, solo en local, con
datos de demostración. Todos son **idempotentes** (`firstOrCreate`): puedes
ejecutarlos varias veces sin duplicar nada y sin pisar lo editado a mano.

Todo se ejecuta por Docker con `make` (nunca `php artisan` en el host):

```bash
make art cmd="db:seed"                              # DatabaseSeeder (seguro en producción)
make art cmd="db:seed --class=DemoDataSeeder"       # demo, solo local
make art cmd="migrate:fresh --seed"                 # ⚠️ borra todo y siembra
```

## Qué hace cada seeder

| Seeder | Qué crea | Dónde corre |
|---|---|---|
| `PermissionSeeder` | Los permisos que usan rutas, policies, menú y controllers (`recurso.accion`, guard `api`) | todos los entornos |
| `RoleSeeder` | `admin`, `manager`, `user` y sus permisos (llama antes a `PermissionSeeder`) | todos los entornos |
| `AdminUserSeeder` | Primer administrador desde `.env` | todos los entornos |
| `DemoDataSeeder` | Un usuario por rol y algunos usuarios normales, contraseña `password` | **solo local** (se niega en producción) |
| `MenuSeeder`, `MailSystemSeeder`, `LlmAreaSeeder` | Datos de esos módulos | con `--class`, si el proyecto los usa |

`DatabaseSeeder` ejecuta `RoleSeeder` y `AdminUserSeeder`. **No** ejecuta el demo.

### Roles base

- **admin**: super admin. Pasa cualquier comprobación de permiso (ver `docs/permissions.md`);
  además se le asignan todos los permisos para que la UI de roles los muestre.
- **manager**: `users.read/create/update/manage-roles` y `roles.read/create/update`.
- **user**: sin permisos de administración (es el rol por defecto de los registros,
  `AUTH_DEFAULT_ROLE`). Su perfil y sus sesiones no piden permisos.

Los seeders solo **añaden** permisos a los roles: nunca quitan los que hayas dado a mano.

## Administrador inicial

`AdminUserSeeder` lee de `.env` (config `auth.initial_admin`):

```dotenv
ADMIN_EMAIL=admin@tu-dominio.com
ADMIN_PASSWORD=una-contraseña-larga-y-única
ADMIN_NAME=Administrador        # opcional
```

- Sin `ADMIN_EMAIL`: no crea nada.
- Sin `ADMIN_PASSWORD` **en producción**: no crea el usuario y lo avisa. No existe contraseña por defecto.
- Sin `ADMIN_PASSWORD` fuera de producción: genera una al azar y la muestra una sola vez por consola.
- Si el usuario ya existe, no cambia su contraseña; solo se asegura de que tenga el rol `admin`.

## Añadir permisos o roles al proyecto

1. Añade el permiso a `PermissionSeeder::catalog()` (nombre `recurso.accion`, en minúsculas;
   la acción puede llevar guion: `users.manage-roles`).
2. Si un rol base debe tenerlo, añádelo en `RoleSeeder`.
3. Úsalo en la ruta (`permission:recurso.accion,api`) o en la policy (`$user->can('recurso.accion')`).
4. `PermissionCatalogTest` falla si una ruta/policy/menú usa un permiso sin sembrar, o si hay uno sembrado que el código no usa.

En un entorno que ya tiene datos, `make art cmd="db:seed --class=PermissionSeeder"` crea los
permisos nuevos sin tocar nada más (y vacía la caché de permisos).

## Verificar

```bash
make art cmd="permission:show"                      # tabla de roles y permisos (comando de Spatie)
make art cmd="tinker"
>>> App\Models\Role::pluck('name')
>>> App\Models\User::where('email', 'admin@tu-dominio.com')->first()->getRoleNames()
```

## Archivos relacionados

- `database/seeders/DatabaseSeeder.php`, `PermissionSeeder.php`, `RoleSeeder.php`, `AdminUserSeeder.php`, `DemoDataSeeder.php`
- `tests/Feature/Authorization/PermissionCatalogTest.php`
- `docs/permissions.md`

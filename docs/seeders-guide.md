# Guía de Seeders

Esta guía explica cómo ejecutar los seeders para poblar la base de datos con datos iniciales.

## 📋 Contenido de los Seeders

### RoleSeeder
Crea 6 roles base del sistema:
- **admin**: Administrador con acceso total
- **manager**: Gerente con permisos de gestión y supervisión
- **user**: Usuario estándar con permisos básicos
- **guest**: Invitado con acceso de solo lectura
- **moderator**: Moderador con permisos de moderación de contenido
- **editor**: Editor con permisos de edición de contenido

### PermissionSeeder
Crea 30 permisos organizados por recursos:
- **Usuarios**: create, read, update, delete, manage-roles
- **Roles**: create, read, update, delete, manage-permissions
- **Permisos**: create, read, update, delete
- **Posts**: create, read, update, update-any, delete, delete-any, moderate
- **Comentarios**: create, read, update, delete, moderate
- **Sistema**: settings, logs, backup, users

Los permisos se asignan automáticamente a los roles según su jerarquía.

### UserSeeder
Crea 12 usuarios de prueba con diferentes roles asignados:

| Usuario | Email | Roles | Email Verificado |
|---------|-------|-------|------------------|
| Admin User | admin@apygg.com | admin | ✅ |
| Manager User | manager@apygg.com | manager | ✅ |
| John Doe | john.doe@example.com | user, editor | ✅ |
| Jane Smith | jane.smith@example.com | user, moderator | ✅ |
| Bob Johnson | bob.johnson@example.com | user | ✅ |
| Alice Williams | alice.williams@example.com | user | ✅ |
| Charlie Brown | charlie.brown@example.com | user | ✅ |
| Diana Prince | diana.prince@example.com | user | ✅ |
| Moderator User | moderator@apygg.com | moderator | ✅ |
| Editor User | editor@apygg.com | editor | ✅ |
| Guest User | guest@apygg.com | guest | ❌ |
| Test User | test@apygg.com | user (+ permisos directos) | ✅ |

**⚠️ IMPORTANTE**: Todos los usuarios tienen la misma contraseña por defecto: `password`

## 🚀 Cómo Ejecutar los Seeders

### Opción 1: Ejecutar todos los seeders (Recomendado)

Ejecuta el `DatabaseSeeder` que ejecuta todos los seeders en el orden correcto:

```bash
# Desde Docker (recomendado)
make sh
php artisan db:seed

# O directamente desde Docker
docker compose exec app php artisan db:seed
```

### Opción 2: Ejecutar seeders individuales

Si necesitas ejecutar solo un seeder específico:

```bash
# Ejecutar solo RoleSeeder
php artisan db:seed --class=RoleSeeder

# Ejecutar solo PermissionSeeder
php artisan db:seed --class=PermissionSeeder

# Ejecutar solo UserSeeder
php artisan db:seed --class=UserSeeder
```

### Opción 3: Refrescar base de datos y ejecutar seeders

Si quieres limpiar la base de datos y ejecutar todas las migraciones y seeders desde cero:

```bash
# Desde Docker
make sh
php artisan migrate:fresh --seed

# O directamente
docker compose exec app php artisan migrate:fresh --seed
```

**⚠️ ADVERTENCIA**: `migrate:fresh` elimina todas las tablas y datos existentes.

## 📝 Orden de Ejecución

Los seeders deben ejecutarse en este orden:

1. **RoleSeeder** - Crea los roles base
2. **PermissionSeeder** - Crea permisos y los asigna a roles (requiere roles)
3. **UserSeeder** - Crea usuarios y los asigna a roles (requiere roles y permisos)

El `DatabaseSeeder` ejecuta automáticamente los seeders en el orden correcto.

## 🔐 Credenciales de Acceso

### Usuario Administrador
- **Email**: `admin@apygg.com`
- **Password**: `password`
- **Rol**: Administrador con todos los permisos

### Otros Usuarios de Prueba
Todos los usuarios tienen la contraseña: `password`

Puedes usar cualquier usuario de la lista anterior para probar diferentes niveles de acceso según sus roles.

## 🔄 Re-ejecutar Seeders

Los seeders están diseñados para ser **idempotentes** (pueden ejecutarse múltiples veces sin duplicar datos):

- `RoleSeeder`: Usa `firstOrCreate()` - solo crea si no existe
- `PermissionSeeder`: Usa `firstOrCreate()` - solo crea si no existe
- `UserSeeder`: Usa `firstOrCreate()` - solo crea si no existe

Si necesitas actualizar datos existentes, puedes:

1. Eliminar los datos manualmente y re-ejecutar
2. Usar `migrate:fresh --seed` para empezar desde cero

## 📊 Verificar Datos Creados

Puedes verificar que los seeders funcionaron correctamente:

```bash
# Ver roles creados
php artisan tinker
>>> App\Models\Role::count()
>>> App\Models\Role::pluck('name')

# Ver permisos creados
>>> App\Models\Permission::count()
>>> App\Models\Permission::pluck('name')

# Ver usuarios creados
>>> App\Models\User::count()
>>> App\Models\User::pluck('email')

# Ver roles de un usuario
>>> $user = App\Models\User::where('email', 'admin@apygg.com')->first()
>>> $user->roles->pluck('name')
```

## 🛠️ Troubleshooting

### Error: "Roles no encontrados"
Si ejecutas `PermissionSeeder` o `UserSeeder` antes de `RoleSeeder`, verás este error. Ejecuta primero `RoleSeeder` o usa `DatabaseSeeder`.

### Error: "Permisos no encontrados"
Si ejecutas `UserSeeder` antes de `PermissionSeeder`, algunos permisos directos no se asignarán. Ejecuta primero `PermissionSeeder` o usa `DatabaseSeeder`.

### Datos duplicados
Los seeders usan `firstOrCreate()` para evitar duplicados. Si necesitas recrear datos, elimina primero los existentes o usa `migrate:fresh --seed`.

## 📚 Archivos Relacionados

- `database/seeders/DatabaseSeeder.php` - Seeder principal
- `database/seeders/RoleSeeder.php` - Crea roles
- `database/seeders/PermissionSeeder.php` - Crea permisos
- `database/seeders/UserSeeder.php` - Crea usuarios
- `database/seeders/initial_data.sql` - SQL original (ya no necesario, pero se mantiene como referencia)

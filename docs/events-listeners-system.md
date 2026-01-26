# Sistema de Eventos y Listeners

## Descripción General

El sistema de eventos y listeners de Laravel permite implementar una arquitectura basada en eventos, donde las acciones del sistema disparan eventos que son procesados por listeners específicos. Esto facilita la separación de responsabilidades, mejora la mantenibilidad y permite ejecutar tareas de forma asíncrona.

## Arquitectura

### Componentes Principales

1. **Eventos (`app/Events/`)**: Representan acciones que ocurren en el sistema
2. **Listeners (`app/Listeners/`)**: Procesan los eventos y ejecutan acciones específicas
3. **EventServiceProvider**: Registra las relaciones entre eventos y listeners

## Eventos Implementados

### Eventos de Usuario

#### `UserCreated`
Disparado cuando se crea un nuevo usuario.

**Dónde se dispara:**
- `UserService::create()`

**Propiedades:**
- `User $user` - Usuario creado

**Listeners asociados:**
- `LogUserActivity` - Registra la creación en el log de actividades
- `SendWelcomeEmail` - Envía email de bienvenida en cola
- `InvalidateUserCache` - Invalida caché relacionado con usuarios

---

#### `UserUpdated`
Disparado cuando se actualiza un usuario existente.

**Dónde se dispara:**
- `UserService::update()`

**Propiedades:**
- `User $user` - Usuario actualizado
- `array $oldAttributes` - Valores anteriores del usuario

**Listeners asociados:**
- `LogUserActivity` - Registra la actualización en el log de actividades
- `InvalidateUserCache` - Invalida caché relacionado con usuarios

---

#### `UserDeleted`
Disparado cuando se elimina un usuario (soft delete).

**Dónde se dispara:**
- `UserService::delete()`

**Propiedades:**
- `User $user` - Usuario eliminado

**Listeners asociados:**
- `LogUserActivity` - Registra la eliminación en el log de actividades
- `InvalidateUserCache` - Invalida caché relacionado con usuarios

---

#### `UserRestored`
Disparado cuando se restaura un usuario eliminado.

**Dónde se dispara:**
- `UserService::restore()`

**Propiedades:**
- `User $user` - Usuario restaurado

**Listeners asociados:**
- `LogUserActivity` - Registra la restauración en el log de actividades
- `InvalidateUserCache` - Invalida caché relacionado con usuarios

---

#### `UserLoggedIn`
Disparado cuando un usuario inicia sesión exitosamente.

**Dónde se dispara:**
- `AuthService::authenticate()`

**Propiedades:**
- `User $user` - Usuario que inició sesión
- `?string $ipAddress` - Dirección IP del cliente
- `?string $userAgent` - User agent del cliente

**Listeners asociados:**
- `LogAuthEvents::handleUserLoggedIn()` - Registra el login en el log de autenticación

---

#### `UserLoggedOut`
Disparado cuando un usuario cierra sesión (token revocado).

**Dónde se dispara:**
- `AuthService::revokeToken()`

**Propiedades:**
- `User $user` - Usuario que cerró sesión
- `?string $ipAddress` - Dirección IP del cliente

**Listeners asociados:**
- `LogAuthEvents::handleUserLoggedOut()` - Registra el logout en el log de autenticación

---

### Eventos de Autorización

#### `RoleAssigned`
Disparado cuando se asigna un rol a un usuario.

**Dónde se dispara:**
- `UserService::assignRoles()` (cuando se agregan roles nuevos)

**Propiedades:**
- `User $user` - Usuario al que se asignó el rol
- `Role $role` - Rol asignado

**Listeners asociados:**
- `InvalidatePermissionsCache` - Invalida caché de permisos y roles

---

#### `RoleRemoved`
Disparado cuando se remueve un rol de un usuario.

**Dónde se dispara:**
- `UserService::removeRole()`
- `UserService::assignRoles()` (cuando se remueven roles)

**Propiedades:**
- `User $user` - Usuario del que se removió el rol
- `Role $role` - Rol removido

**Listeners asociados:**
- `InvalidatePermissionsCache` - Invalida caché de permisos y roles

---

#### `PermissionGranted`
Disparado cuando se otorga un permiso directo a un usuario.

**Dónde se dispara:**
- `UserService::assignPermissions()` (cuando se agregan permisos nuevos)

**Propiedades:**
- `User $user` - Usuario al que se otorgó el permiso
- `Permission $permission` - Permiso otorgado

**Listeners asociados:**
- `InvalidatePermissionsCache` - Invalida caché de permisos y roles

---

#### `PermissionRevoked`
Disparado cuando se revoca un permiso directo de un usuario.

**Dónde se dispara:**
- `UserService::removePermission()`
- `UserService::assignPermissions()` (cuando se remueven permisos)

**Propiedades:**
- `User $user` - Usuario del que se revocó el permiso
- `Permission $permission` - Permiso revocado

**Listeners asociados:**
- `InvalidatePermissionsCache` - Invalida caché de permisos y roles

---

## Listeners Implementados

### `LogUserActivity`
Registra actividades de usuarios en el sistema de logging.

**Eventos que escucha:**
- `UserCreated`
- `UserUpdated`
- `UserDeleted`
- `UserRestored`

**Funcionalidad:**
- Utiliza `ActivityLogger` para registrar cambios en usuarios
- Captura valores anteriores y nuevos (en caso de actualización)
- Filtra campos sensibles automáticamente

**Ejecución:** Asíncrona (cola)

---

### `LogAuthEvents`
Registra eventos de autenticación en el sistema de logging.

**Eventos que escucha:**
- `UserLoggedIn` → `handleUserLoggedIn()`
- `UserLoggedOut` → `handleUserLoggedOut()`

**Funcionalidad:**
- Utiliza `AuthLogger` para registrar eventos de autenticación
- Captura IP y User Agent para auditoría de seguridad

**Ejecución:** Asíncrona (cola)

---

### `SendWelcomeEmail`
Envía email de bienvenida a nuevos usuarios.

**Eventos que escucha:**
- `UserCreated`

**Funcionalidad:**
- Despacha `SendWelcomeEmailJob` en la cola `default`
- El job maneja el envío del email usando `NotificationService`

**Ejecución:** Asíncrona (cola)

---

### `InvalidateUserCache`
Invalida caché relacionado con usuarios.

**Eventos que escucha:**
- `UserCreated`
- `UserUpdated`
- `UserDeleted`
- `UserRestored`

**Funcionalidad:**
- Invalida caché específico del usuario (`user:{id}`)
- Invalida caché de listados de usuarios (`users`)
- En actualizaciones, también invalida caché de permisos y roles del usuario

**Ejecución:** Asíncrona (cola)

---

### `InvalidatePermissionsCache`
Invalida caché relacionado con permisos y roles.

**Eventos que escucha:**
- `RoleAssigned`
- `RoleRemoved`
- `PermissionGranted`
- `PermissionRevoked`

**Funcionalidad:**
- Invalida caché de permisos del usuario (`user:{id}:permissions`)
- Invalida caché de roles del usuario (`user:{id}:roles`)
- Invalida caché del usuario (`user:{id}`)
- Invalida caché global de permisos y roles

**Ejecución:** Asíncrona (cola)

---

## Configuración

### EventServiceProvider

El `EventServiceProvider` se encuentra en `app/Providers/EventServiceProvider.php` y registra todas las relaciones entre eventos y listeners:

```php
protected $listen = [
    UserCreated::class => [
        LogUserActivity::class,
        SendWelcomeEmail::class,
        InvalidateUserCache::class,
    ],
    // ... más eventos
];
```

### Registro en Bootstrap

El `EventServiceProvider` está registrado en `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    // ... otros providers
];
```

---

## Uso

### Disparar Eventos Manualmente

Puedes disparar eventos manualmente usando la función `event()`:

```php
use App\Events\UserCreated;

event(new UserCreated($user));
```

### Escuchar Eventos en Tiempo Real

Para escuchar eventos sin registrarlos en el `EventServiceProvider`, puedes usar `Event::listen()`:

```php
use Illuminate\Support\Facades\Event;
use App\Events\UserCreated;

Event::listen(UserCreated::class, function (UserCreated $event) {
    // Tu lógica aquí
});
```

### Crear Nuevos Eventos

1. Crear el evento en `app/Events/`:

```php
<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserEmailChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $oldEmail
    ) {}
}
```

2. Crear el listener en `app/Listeners/`:

```php
<?php

namespace App\Listeners;

use App\Events\UserEmailChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyEmailChange implements ShouldQueue
{
    public function handle(UserEmailChanged $event): void
    {
        // Tu lógica aquí
    }
}
```

3. Registrar en `EventServiceProvider`:

```php
protected $listen = [
    UserEmailChanged::class => [
        NotifyEmailChange::class,
    ],
];
```

---

## Ejecución Asíncrona

Todos los listeners implementan `ShouldQueue`, lo que significa que se ejecutan de forma asíncrona mediante colas. Esto mejora el rendimiento de las respuestas HTTP.

### Requisitos

- **Cola configurada**: Redis como driver de colas
- **Workers activos**: Laravel Horizon o `php artisan queue:work`

### Monitoreo

Puedes monitorear la ejecución de eventos y listeners en:
- **Laravel Horizon**: Dashboard en `/horizon`
- **Laravel Telescope**: Sección de Jobs y Events

---

## Mejores Prácticas

1. **Separación de responsabilidades**: Cada listener debe tener una única responsabilidad
2. **Ejecución asíncrona**: Usa `ShouldQueue` para listeners que no bloqueen la respuesta HTTP
3. **Manejo de errores**: Los listeners deben manejar errores sin interrumpir el flujo principal
4. **Logging**: Registra acciones importantes para auditoría
5. **Invalidación de caché**: Invalida caché relacionado cuando sea necesario
6. **Nombres descriptivos**: Usa nombres claros para eventos y listeners

---

## Troubleshooting

### Los eventos no se disparan

1. Verifica que el `EventServiceProvider` esté registrado en `bootstrap/providers.php`
2. Verifica que los eventos estén correctamente registrados en `$listen`
3. Limpia el caché de configuración: `php artisan config:clear`

### Los listeners no se ejecutan

1. Verifica que los workers de cola estén activos: `php artisan queue:work`
2. Revisa los logs en `storage/logs/`
3. Verifica el estado de las colas en Horizon

### Errores en listeners

1. Revisa los logs de Laravel: `storage/logs/laravel.log`
2. Revisa los logs de Horizon si está configurado
3. Verifica que las dependencias del listener estén disponibles

---

## Ejemplos de Uso

### Ejemplo 1: Crear Usuario

```php
use App\Services\UserService;

$userService = app(UserService::class);
$user = $userService->create([
    'name' => 'Juan Pérez',
    'email' => 'juan@example.com',
    'password' => 'password123',
]);

// Automáticamente se disparan:
// - UserCreated → LogUserActivity, SendWelcomeEmail, InvalidateUserCache
```

### Ejemplo 2: Actualizar Usuario

```php
$user = $userService->update($userId, [
    'name' => 'Juan Carlos Pérez',
]);

// Automáticamente se disparan:
// - UserUpdated → LogUserActivity, InvalidateUserCache
```

### Ejemplo 3: Asignar Roles

```php
$user = $userService->assignRoles($userId, ['admin', 'editor']);

// Automáticamente se disparan:
// - RoleAssigned (por cada rol nuevo) → InvalidatePermissionsCache
```

### Ejemplo 4: Login

```php
use App\Services\AuthService;

$authService = app(AuthService::class);
$result = $authService->authenticate([
    'email' => 'juan@example.com',
    'password' => 'password123',
], $request->ip());

// Automáticamente se dispara:
// - UserLoggedIn → LogAuthEvents
```

---

## Referencias

- [Laravel Events Documentation](https://laravel.com/docs/events)
- [Laravel Queues Documentation](https://laravel.com/docs/queues)
- [Laravel Horizon Documentation](https://laravel.com/docs/horizon)

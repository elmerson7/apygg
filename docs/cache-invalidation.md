# Cache Invalidation Inteligente

## Descripción

Sistema de invalidación automática de cache basado en eventos y observadores de modelos. Garantiza que el cache se actualice automáticamente cuando cambian los datos.

## Componentes

### 1. Invalidación por Tags

El `CacheService` soporta invalidación por tags, permitiendo invalidar grupos relacionados de cache:

```php
use App\Services\CacheService;

// Invalidar un tag específico
CacheService::forgetTag('user:123');

// Invalidar múltiples tags
CacheService::forgetTags(['users', 'permissions']);
```

### 2. Invalidación por Patrones

Permite invalidar cache masivamente usando patrones con wildcards:

```php
use App\Services\CacheService;

// Invalidar todo el cache de usuarios
CacheService::forgetPattern('user:*');

// Invalidar cache de permisos de un usuario específico
CacheService::forgetPattern('user:123:*');

// Invalidar usando SCAN (más eficiente para grandes volúmenes)
CacheService::forgetPatternScan('user:*', 100);
```

### 3. Observers Automáticos

Los Observers invalidan cache automáticamente cuando cambian modelos:

**Modelos con Observers:**
- `ApiKey` → `ApiKeyObserver`
- `Webhook` → `WebhookObserver`
- `User` → Invalidación mediante eventos (no Observer)

**Eventos que disparan invalidación:**
- `created` - Al crear un registro
- `updated` - Al actualizar un registro
- `deleted` - Al eliminar (soft delete)
- `restored` - Al restaurar un registro eliminado

### 4. Listeners de Eventos

Listeners específicos para eventos del sistema:

**InvalidateUserCache:**
- Escucha: `UserCreated`, `UserUpdated`, `UserDeleted`, `UserRestored`
- Invalida: `user:{id}`, `user`, `users`, `user:{id}:permissions`, `user:{id}:roles`

**InvalidatePermissionsCache:**
- Escucha: `RoleAssigned`, `RoleRemoved`, `PermissionGranted`, `PermissionRevoked`
- Invalida: `user:{id}:permissions`, `user:{id}:roles`, `permissions`, `roles`

## Comando Artisan

### Invalidar por Tag

```bash
php artisan cache:invalidate --tag=users
```

### Invalidar Múltiples Tags

```bash
php artisan cache:invalidate --tags=users,permissions,roles
```

### Invalidar por Patrón

```bash
php artisan cache:invalidate --pattern=user:*
php artisan cache:invalidate --pattern=user:123:*
```

### Invalidar Todo

```bash
php artisan cache:invalidate --all
```

## Uso en Código

### Cachear con Tags

```php
use App\Services\CacheService;

// Cachear con tag automático
CacheService::rememberUser($userId, function () use ($userId) {
    return User::with(['roles', 'permissions'])->find($userId);
});

// Cachear entidad con tag
CacheService::rememberEntity('roles', function () {
    return Role::all();
});
```

### Invalidación Manual

```php
use App\Services\CacheService;

// Invalidar tag específico
CacheService::forgetTag('user:123');

// Invalidar múltiples tags
CacheService::forgetTags(['users', 'permissions']);

// Invalidar por patrón
CacheService::forgetPattern('user:*');

// Invalidar key específica
CacheService::forget('user:123');
```

## Tags Comunes

- `user:{id}` - Cache específico de un usuario
- `user` - Cache general de usuarios
- `users` - Cache de listados de usuarios
- `permissions` - Cache de permisos
- `roles` - Cache de roles
- `api-keys` - Cache de API Keys
- `webhooks` - Cache de Webhooks
- `searches` - Cache de búsquedas

## Performance

### Invalidación por Tags
- ✅ Rápido si el driver soporta tags nativamente (Redis)
- ⚠️ Más lento si requiere fallback manual

### Invalidación por Patrones
- ⚠️ `forgetPattern()` usa `KEYS` - puede ser lento en producción
- ✅ `forgetPatternScan()` usa `SCAN` - más eficiente para grandes volúmenes

**Recomendación:** Usar `forgetPatternScan()` para patrones que puedan afectar muchos elementos.

## Troubleshooting

### Cache no se invalida
1. Verificar que Redis esté disponible
2. Verificar que el Observer esté registrado en `AppServiceProvider`
3. Verificar que el listener esté registrado en `EventServiceProvider`
4. Revisar logs: `storage/logs/laravel.log`

### Invalidación lenta
- Usar `forgetPatternScan()` en lugar de `forgetPattern()` para grandes volúmenes
- Considerar invalidar por tags en lugar de patrones cuando sea posible
- Verificar que Redis no esté sobrecargado

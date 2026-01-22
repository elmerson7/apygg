# CacheService - Guía de Uso

## Descripción

`CacheService` es un servicio centralizado para operaciones de caché que proporciona:
- Métodos simplificados para operaciones comunes
- Soporte para tags para invalidación selectiva
- Métodos especializados para usuarios, entidades y búsquedas
- Métricas de uso del caché

## Métodos Básicos

### get() - Obtener valor

```php
use App\Infrastructure\Services\CacheService;

$value = CacheService::get('key');
$value = CacheService::get('key', 'default'); // Con valor por defecto
```

### set() - Guardar valor

```php
CacheService::set('key', 'value', 3600); // TTL en segundos
CacheService::set('key', 'value'); // Usa TTL por defecto (1 hora)
```

### forget() - Eliminar valor

```php
CacheService::forget('key');
```

### remember() - Obtener o calcular

```php
$value = CacheService::remember('key', 3600, function() {
    return expensiveOperation();
});
```

## Tags para Invalidación Selectiva

### Usar tags

```php
// Guardar con tag
CacheService::tag('user:123')->set('profile', $data);
CacheService::tag('user:123')->set('roles', $roles);

// Invalidar todo el tag
CacheService::forgetTag('user:123'); // Elimina profile y roles
```

### Invalidar múltiples tags

```php
CacheService::forgetTags(['user:123', 'user:456']);
```

## Métodos Especializados

### rememberUser() - Cache de usuario

```php
$user = CacheService::rememberUser($userId, function() use ($userId) {
    return User::with('roles', 'permissions')->find($userId);
});

// Al actualizar el usuario, invalidar su caché
$user->update($data);
CacheService::forgetTag("user:{$userId}");
```

### rememberEntity() - Cache de entidades

```php
// Cache de roles (se invalida con tag 'entity:roles')
$roles = CacheService::rememberEntity('roles', function() {
    return Role::all();
});

// Invalidar cuando se actualiza
Role::create($data);
CacheService::forgetTag('entity:roles');
```

### rememberSearch() - Cache de búsquedas

```php
$results = CacheService::rememberSearch('juan', ['status' => 'active'], function() {
    return User::search('juan')->where('status', 'active')->get();
});

// Invalidar todas las búsquedas
CacheService::forgetTag('searches');
```

## Métricas

### getAllMetrics() - Obtener estadísticas

```php
$metrics = CacheService::getAllMetrics();
/*
[
    'driver' => 'redis',
    'prefix' => 'apygg',
    'hit_rate' => 85.5,        // Porcentaje de hits
    'memory_used' => '125MB',   // Memoria usada
    'keys_count' => 1523,       // Número de keys
    'tags_count' => 45          // Número de tags
]
*/
```

## Ejemplos de Uso Real

### En un Controller

```php
class UserController extends BaseController
{
    public function show(string $id)
    {
        $user = CacheService::rememberUser($id, function() use ($id) {
            return User::with('roles', 'permissions')->findOrFail($id);
        });

        return $this->sendSuccess($user);
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        $user = User::findOrFail($id);
        $user->update($request->validated());

        // Invalidar caché del usuario
        CacheService::forgetTag("user:{$id}");

        return $this->sendSuccess($user);
    }
}
```

### En un Service

```php
class RoleService
{
    public function getAllRoles()
    {
        return CacheService::rememberEntity('roles', function() {
            return Role::with('permissions')->get();
        });
    }

    public function createRole(array $data)
    {
        $role = Role::create($data);
        
        // Invalidar caché de roles
        CacheService::forgetTag('entity:roles');
        
        return $role;
    }
}
```

## TTLs por Defecto

- **user**: 3600 segundos (1 hora)
- **entity**: 7200 segundos (2 horas)
- **search**: 1800 segundos (30 minutos)
- **default**: 3600 segundos (1 hora)

### Cambiar TTL por defecto

```php
CacheService::setDefaultTtl('user', 7200); // 2 horas
$ttl = CacheService::getDefaultTtl('user');
```

## Operaciones Múltiples

### getMultiple() - Obtener múltiples keys

```php
$values = CacheService::getMultiple(['key1', 'key2', 'key3']);
// ['key1' => value1, 'key2' => value2, 'key3' => value3]
```

### setMultiple() - Guardar múltiples valores

```php
CacheService::setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

## Limpiar Todo el Caché

```php
CacheService::flush(); // ⚠️ Cuidado: elimina TODO
```

## Verificar Existencia

```php
if (CacheService::has('key')) {
    // La key existe
}
```

## Mejores Prácticas

1. **Usar tags para agrupaciones lógicas**
   ```php
   CacheService::tag('user:123')->set('profile', $data);
   CacheService::tag('user:123')->set('settings', $settings);
   ```

2. **Invalidar al actualizar**
   ```php
   $user->update($data);
   CacheService::forgetTag("user:{$user->id}");
   ```

3. **Usar métodos especializados**
   ```php
   // ✅ Mejor
   CacheService::rememberUser($id, $callback);
   
   // ❌ Evitar
   CacheService::remember("user:{$id}", 3600, $callback);
   ```

4. **Monitorear métricas**
   ```php
   $metrics = CacheService::getAllMetrics();
   if ($metrics['hit_rate'] < 50) {
       // Revisar estrategia de caché
   }
   ```

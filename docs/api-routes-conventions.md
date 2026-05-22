# Convenciones de Rutas API

## Estructura Simple

No hay prefijos fijos para admin/web/aula. La autenticación y permisos controlan el acceso.

| Prefijo | Uso | Auth |
|---------|-----|------|
| `/auth` | Autenticación | No |
| `/user` | Datos del usuario actual | Sí |
| `/recursos` | CRUD recursos | Sí (por permisos) |

---

## Estructura de Rutas

### `/auth` - Autenticación

```php
Route::prefix('auth')->group(function () {
    Route::post('/login', ...);      // Público
    Route::post('/register', ...);   // Público
    Route::get('/me', ...);          // Auth
    Route::post('/refresh', ...);    // Auth
    Route::post('/logout', ...);     // Auth
});
```

### `/user` - Datos del usuario actual

```php
Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('/profile', ...);     // Mi perfil
    Route::put('/preferences', ...); // Mis preferencias
});
```

### CRUD Recursos

```php
Route::middleware(['auth:api'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
});
```

---

## Permisos

Los permisos controlan el acceso, no el prefijo de URL.

```php
// Ejemplo: Users CRUD
Route::middleware(['auth:api'])->group(function () {
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.read');
    
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('permission:users.create');
});
```

---

## Convenciones de Naming

### Controladores

```php
// ✅ Correcto - nombre del recurso en plural
Route::get('/users', [UserController::class, 'index']);
Route::get('/roles', [RoleController::class, 'index']);

// ❌ Incorrecto
Route::get('/admin-users', [UserController::class, 'index']);
```

### Nombres de rutas

```php
Route::get('/users', ...)->name('users.index');
Route::get('/users/{id}', ...)->name('users.show');
Route::post('/users', ...)->name('users.store');
```

---

## Ejemplo Completo

```php
// ============================================================================
// AUTH - Público
// ============================================================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', ...);
    Route::post('/reset-password', ...);
});

// ============================================================================
// AUTH - Protegido
// ============================================================================
Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// ============================================================================
// USER - Datos del usuario actual
// ============================================================================
Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::get('/preferences', [PreferencesController::class, 'show']);
    Route::put('/preferences', [PreferencesController::class, 'update']);
});

// ============================================================================
// CRUD RECURSOS
// ============================================================================
Route::middleware(['auth:api'])->group(function () {
    // Users
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    
    // Roles
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.read');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
});
```

---

## Paginación

Todos los endpoints que retornan listas usan paginación por defecto.

### Parámetros

| Parámetro | Default | Máximo | Descripción |
|-----------|---------|--------|-------------|
| `page` | 1 | - | Página actual |
| `per_page` | 20 | 100 | Items por página |
| `sort` | created_at | - | Campo a ordenar |
| `order` | desc | asc/desc | Dirección del ordenamiento |

### Ejemplo

```
GET /users?page=2&per_page=50&sort=name&order=asc
```

### Respuesta

```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "current_page": 2,
    "per_page": 50,
    "total": 150,
    "last_page": 3,
    "from": 51,
    "to": 100
  },
  "links": {
    "first": "/users?page=1",
    "last": "/users?page=3",
    "prev": "/users?page=1",
    "next": "/users?page=3"
  },
  "meta": {...}
}
```

---

## Reglas de Oro

1. **Prefijo `/auth`** → autenticación (login, register, me, etc.)
2. **Prefijo `/user`** → datos del usuario autenticado (no admin)
3. **Recursos en plural** → CRUD sin prefijo admin/web
4. **Permisos** → controlan acceso, no prefijos de URL
5. **Paginación** → siempre activos, 20 por defecto

---

**Última actualización:** 2026-05-22  
**Versión:** 2.0.0
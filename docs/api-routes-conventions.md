# Convenciones de Rutas API

## Cómo identificar rutas públicas vs autenticadas

### Por URL

| Patrón | Ejemplo | Uso | Auth |
|--------|---------|-----|------|
| **Recurso en plural, sin prefijo `user/`** | `/events`, `/events/{id}` | Catálogo o recurso público (web, SEO, landing) | No |
| **Prefijo `user/`** | `/user/events`, `/user/profile`, `/user/payments` | Recurso del **usuario autenticado** (sus datos) | Sí (JWT) |
| **Recurso en plural con CRUD** | `/users`, `/users/{id}` | Colección/administración (ej. listar/editar usuarios) | Sí (y normalmente permisos/roles) |
| **Prefijo `/admin`** | `/admin/users`, `/admin/settings`, `/admin/events` | Gestión administrativa (requiere rol admin) | Sí |

### Por nombre de ruta

- `events.index`, `events.show` → públicos (catálogo).
- `user.events.index`, `user.profile.show` → requieren autenticación; son "mis" datos.
- `admin.users.index`, `admin.settings.update` → requieren rol de administrador.

---

## Ejemplo: eventos

- **Público (catálogo web)**: `GET /events`, `GET /events/{type}/{id}`, `GET /events/{id}/reviews`  
  → Cualquiera puede ver el listado y el detalle para mostrarlo en la web.

- **Autenticado (mis datos)**: `GET /user/events`, `GET /user/payments`, `GET /user/classes`  
  → El usuario logueado ve solo sus inscripciones, pagos, clases.

- **Admin (gestión)**: `GET /admin/events`, `POST /admin/events`, `PUT /admin/events/{id}`  
  → Administradores pueden crear, editar y gestionar eventos.

Mismo recurso (eventos), tres contextos:
1. Público bajo `/` - catálogo
2. Privado bajo `/user` - datos del usuario autenticado
3. Administrador bajo `/admin` - gestión

---

## Estructura de Rutas por Sistema

### Web (sin prefijo)
- Rutas públicas sin autenticación
- Uso: Catálogo público, landing pages, SEO
- Ejemplos: `/events`, `/about`, `/contact`

### Aula Virtual (`/user`)
- Rutas protegidas que requieren autenticación JWT
- Uso: Datos privados del usuario autenticado (estudiante/docente)
- Ejemplos: `/user/courses`, `/user/classes`, `/user/profile`

### Admin (`/admin`)
- Rutas protegidas que requieren rol de administrador
- Uso: Gestión y administración del sistema
- Ejemplos: `/admin/users`, `/admin/settings`, `/admin/reports`

---

## Convenciones de Naming

### Nombres de rutas

```php
// Público (web)
Route::get('/events', [EventController::class, 'index'])->name('events.index');
Route::get('/events/{id}', [EventController::class, 'show'])->name('events.show');

// Usuario autenticado
Route::get('/user/events', [EventController::class, 'indexByUser'])->name('user.events.index');
Route::get('/user/profile', [ProfileController::class, 'show'])->name('user.profile.show');

// Administración
Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
```

### Controladores

```php
// ✅ Correcto - agrupar por módulo/sistema
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'indexPublic']);        // Público
});

Route::prefix('user')->group(function () {
    Route::get('/events', [EventController::class, 'indexByUser']);  // Datos del usuario
});

Route::prefix('admin')->group(function () {
    Route::get('/events', [EventController::class, 'indexAdmin']);  // Gestión admin
});
```

---

## Autenticación por Prefijo

| Prefijo | Middleware | Uso típico |
|---------|------------|------------|
| (ninguno) | `optional:auth` o sin auth | Público |
| `/user` | `auth:api` | Datos privados del usuario |
| `/admin` | `auth:api` + rol admin | Administración |

---

## Ejemplo Completo

```php
// ============================================================================
// WEB - Público (sin prefijo)
// ============================================================================
Route::prefix('events')->name('events.')->group(function () {
    Route::get('/', [EventController::class, 'indexPublic'])->name('index');
    Route::get('/{id}', [EventController::class, 'showPublic'])->name('show');
    Route::get('/{id}/reviews', [EventReviewController::class, 'listByEvent'])->name('reviews');
});

// ============================================================================
// AULA VIRTUAL - /user (usuario autenticado)
// ============================================================================
Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('events', [EventController::class, 'indexByUser'])->name('user.events.index');
    Route::get('events/{id}', [EventController::class, 'showForUser'])->name('user.events.show');
    Route::get('payments', [PaymentController::class, 'indexByUser'])->name('user.payments.index');
    Route::get('profile', [ProfileController::class, 'show'])->name('user.profile.show');
});

// ============================================================================
// ADMIN - /admin (administración)
// ============================================================================
Route::middleware(['auth:api'])->prefix('admin')->group(function () {
    Route::get('events', [EventController::class, 'indexAdmin'])->name('admin.events.index');
    Route::post('events', [EventController::class, 'store'])->name('admin.events.store');
    Route::put('events/{id}', [EventController::class, 'update'])->name('admin.events.update');
    Route::delete('events/{id}', [EventController::class, 'destroy'])->name('admin.events.destroy');
    
    Route::get('users', [UserController::class, 'index'])->name('admin.users.index');
    Route::post('users', [UserController::class, 'store'])->name('admin.users.store');
});
```

---

## Reglas de Oro

1. **Nunca duplicar rutas** - Un recurso = una ruta, pero puede tener múltiples contextos (público, usuario, admin)
2. **Usar prefijos correctos** - `/user` para datos del auth, `/admin` para gestión
3. **Nombrar coherentemente** - `user.events.index`, `admin.events.index`
4. **Middleware apropiado** - Público, `auth:api`, o `auth:api` + verificación de rol

---

**Última actualización:** 2026-03-16  
**Versión:** 1.0.0

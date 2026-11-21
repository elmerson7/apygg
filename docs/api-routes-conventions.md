# Convenciones de Rutas API

## Estructura Simple

No hay prefijos fijos para admin/web/aula. La autenticación y permisos controlan el acceso.
Las rutas cuelgan de la raíz (no existe `/api`); un prefijo de versión (`/v1`) es **opcional**
(`API_PREFIX`, ver [Versiones y apps móviles](#versiones-y-apps-móviles)). Con `API_PREFIX` vacío, que es el
valor por defecto, nada de esto cambia.

| Prefijo | Uso | Auth |
|---------|-----|------|
| `/v1` (opcional) | Antepuesto a todo lo de abajo cuando `API_PREFIX=v1` | — |
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

Los permisos controlan el acceso, no el prefijo de URL. El nombre del permiso va
siempre explícito y con el guard (`permission:users.read,api`); no hay mapa
método HTTP → acción. Detalle en [permissions.md](permissions.md).

```php
// Ejemplo: Users CRUD
Route::middleware(['auth:api'])->group(function () {
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.read,api');
    
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('permission:users.create,api');
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
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read,api');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create,api');
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete,api');
    
    // Roles
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.read,api');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create,api');
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete,api');
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

## Versiones y apps móviles

Una app web se actualiza al recargar; una app móvil publicada **no**: hay versiones viejas en la calle meses.
Si tu proyecto alimenta apps móviles, activa el prefijo de versión desde el día uno.

### Activarlo: `API_PREFIX`

```env
API_PREFIX=v1        # también vale /v1 o v1/ (se normaliza a v1). Vacío (por defecto) = rutas en la raíz
```

- Lo lee `config/api.php` (`api.prefix`) y lo aplica `routes/api.php` a un grupo: `GET /users` pasa a `GET /v1/users`.
  En código usa `App\Support\ApiPath` (`prefix()`, `with('/x')`, `strip()`, `is($request, 'patrón')`); nunca leas `env()` fuera de config.
- **Nada cambia con `API_PREFIX` vacío**: mismas rutas, mismo `docs/openapi.json`, mismos health checks, deploy, smoke y CI.
- Se decide al crear el proyecto (`docs/new-project.md`). Cambiarlo después rompe a todos los clientes: una app ya publicada
  sin prefijo no encontraría nada. Para añadir `/v2` junto a `/v1`, ver más abajo.

### Qué queda SIEMPRE fuera del prefijo

Lo llaman servidores, balanceadores o personas (no las apps) y su URL se configura fuera del código:

| Ruta | Por qué |
|---|---|
| `/`, `/up`, `/health`, `/health/*` | balanceadores, `make smoke`, health checks de Docker |
| `/webhooks/inbound/{provider}`, `/webhooks/mail` | proveedores y pasarelas (Stripe...): su URL está escrita en su panel |
| `/docs/api`, `/docs/api.json` | documentación OpenAPI (Scramble) |
| `/backoffice/*`, `/livewire*`, `/horizon`, `/telescope` | paneles web, no API |
| `/test/sentry/*` | diagnóstico (solo local/testing) |

La lista vive en `config/api.php` (`unprefixed`); los tests y los middlewares la comparten. **`/broadcasting/auth` sí lleva
prefijo** (lo llama el cliente de la app con el JWT: `/v1/broadcasting/auth`). Otros efectos con prefijo:
la URL de retorno OAuth (`GOOGLE_REDIRECT_URI`, ...) debe incluir el prefijo (`${APP_URL}/v1/auth/google/callback`); las URLs de
descarga de archivos salen de `route()` y ya lo llevan; los patrones de configuración (`except`, `allow_paths`, rate limiting,
mantenimiento, versión mínima, impersonación, consentimientos...) se escriben **sin** prefijo y valen con y sin él.

### OpenAPI y tipos de los fronts

`docs/openapi.json` se genera SIN prefijo en las rutas (los tipos TypeScript no cambian con `API_PREFIX`). Con prefijo,
`servers[].url` termina en `/v1` y las rutas que nunca lo llevan (salud, webhooks) declaran su propio `servers` raíz.

### Política de cambios (para no romper apps publicadas)

1. **Solo cambios aditivos** dentro de una versión: rutas nuevas, campos nuevos en respuestas, parámetros opcionales nuevos.
2. **Quitar o renombrar** (ruta, campo, valor de enum, hacer obligatorio un campo): primero **deprecar** (`deprecated: true`
   en el contrato, mantener funcionando), luego subir la **versión mínima de app** (`APP_MIN_VERSION*` → 426
   `app_update_required`, ver [sessions-and-2fa.md](sessions-and-2fa.md)) y solo cuando ya no queden apps viejas, quitarlo.
3. **Cambio imposible de hacer compatible**: monta `/v2` como otro grupo en `routes/api.php`
   (`Route::prefix('v2')->group(...)` con sus controllers/Resources) y deja `/v1` vivo hasta retirarlo con el punto 2.

### Comprobación automática: `oasdiff`

CI (job `openapi-breaking`) compara `docs/openapi.json` del PR con el de la rama destino (en push, con el commit anterior)
usando `oasdiff breaking` (imagen Docker de versión fija) y **falla si hay cambios que rompen**. En local:

```bash
make openapi-breaking                 # contra main
make openapi-breaking BASE=<ref>      # contra otra rama o commit
```

Si el cambio es intencionado (ya deprecado y con la versión mínima subida), hay dos escapes explícitos:

- una línea en `docs/openapi-breaking-ignore.txt` con el texto exacto del aviso y, encima, un comentario con el motivo y quién
  lo aprobó (queda en git y se revisa en el PR); o
- la etiqueta `breaking-change-approved` en el PR (`ALLOW_BREAKING=1` en local).

---

## Reglas de Oro

1. **Prefijo `/auth`** → autenticación (login, register, me, etc.)
2. **Prefijo `/user`** → datos del usuario autenticado (no admin)
3. **Recursos en plural** → CRUD sin prefijo admin/web
4. **Permisos** → controlan acceso, no prefijos de URL
5. **Paginación** → siempre activos, 20 por defecto
6. **Apps móviles** → `API_PREFIX=v1` desde el principio y solo cambios aditivos (ver «Versiones y apps móviles»)

---

**Última actualización:** 2026-05-22  
**Versión:** 2.0.0
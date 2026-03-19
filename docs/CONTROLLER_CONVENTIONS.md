# Convenciones de Controladores - APYGG

## Tabla de Contenidos

- [1. Estructura Base](#1-estructura-base)
- [2. Herencia y Traits](#2-herencia-y-traits)
- [3. Propiedades Protegidas](#3-propiedades-protegidas)
- [4. Inyección de Dependencias](#4-inyección-de-dependencias)
- [5. Autorización](#5-autorización)
- [6. Respuestas Estándar](#6-respuestas-estándar)
- [7. Documentación](#7-documentación)
- [8. Manejo de Errores](#8-manejo-de-errores)
- [9. Validación](#9-validación)
- [10. Type Hints y PHPDoc](#10-type-hints-y-phpdoc)
- [11. Nomenclatura](#11-nomenclatura)
- [12. Ejemplos](#12-ejemplos)

---

## 1. Estructura Base

Todos los controladores deben seguir esta estructura:

```php
<?php

namespace App\Http\Controllers\{Module};

use App\Http\Controllers\Controller;
use App\Services\{Module}Service;
use Illuminate\Http\JsonResponse;

/**
 * {Name}Controller
 *
 * Descripción breve del propósito del controlador.
 */
class {Name}Controller extends Controller
{
    protected {Module}Service $service;

    protected ?string $model = {Model}::class;
    protected ?string $resource = {Resource}::class;

    public function __construct({Module}Service $service)
    {
        $this->service = $service;
    }

    // Métodos del controlador
}
```

---

## 2. Herencia y Traits

### ✅ HACER

- **Siempre extender** `App\Http\Controllers\Controller` base
- Usar los traits `AuthorizesRequests` y `ValidatesRequests` (ya incluidos en Controller base)

### ❌ NO HACER

- No crear controladores sin extender Controller base
- No usar traits de forma redundante (ya están en Controller)

```php
// ✅ Correcto
class UserController extends Controller
{
    // ...
}

// ❌ Incorrecto
class UserController
{
    use AuthorizesRequests, ValidatesRequests;
    // ...
}
```

---

## 3. Propiedades Protegidas

Definir estas propiedades cuando corresponda:

```php
/**
 * Modelo asociado al controlador
 */
protected ?string $model = User::class;

/**
 * Resource para transformar respuestas
 */
protected ?string $resource = UserResource::class;

/**
 * Relaciones permitidas para eager loading
 */
protected array $allowedRelations = ['roles', 'permissions', 'profile'];

/**
 * Campos permitidos para ordenamiento
 */
protected array $allowedSortFields = ['name', 'email', 'created_at'];

/**
 * Campos permitidos para filtrado
 */
protected array $allowedFilterFields = ['email', 'state_id'];
```

---

## 4. Inyección de Dependencias

### ✅ HACER

- **Siempre inyectar Services** en el constructor
- Usar **type hints** completos
- Declarar propiedades con visibilidad y tipo

```php
// ✅ Correcto
protected UserService $userService;

public function __construct(UserService $userService)
{
    $this->userService = $userService;
}
```

### ❌ NO HACER

- No incluir lógica de negocio directamente en controladores
- No hacer consultas Eloquent complejas en controladores

```php
// ❌ Incorrecto - lógica en controlador
public function store(Request $request): JsonResponse
{
    $user = User::create($request->validated());
    $user->roles()->attach($request->role_ids);
    $user->profile()->create($request->profile);
    // ...
}

// ✅ Correcto - delegar a Service
public function store(StoreUserRequest $request): JsonResponse
{
    $user = $this->userService->create(
        $request->validated(),
        $request->role_ids
    );
    return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
}
```

---

## 5. Autorización

### ✅ HACER

- Usar **Policies** de Laravel con `$this->authorize()`
- Verificar autorización **antes** de cualquier operación

```php
// ✅ Correcto
public function update(UpdateUserRequest $request, string $id): JsonResponse
{
    $user = $this->userService->find($id);
    $this->authorize('update', $user);
    
    $updated = $this->userService->update($id, $request->validated());
    return $this->sendSuccess($updated, 'Usuario actualizado exitosamente');
}
```

### ❌ NO HACER

- No verificar permisos manualmente con `hasPermission()` o similar

```php
// ❌ Incorrecto
if (!$user->hasPermission('users.update')) {
    return response()->json(['message' => 'No autorizado'], 403);
}

// ✅ Correcto
$this->authorize('update', $user);
```

---

## 6. Respuestas Estándar

### Métodos Helper del Controller Base

Usar **exclusivamente** estos métodos para respuestas:

#### `sendSuccess()`

```php
protected function sendSuccess(
    $data = null,
    string $message = 'Operación exitosa',
    int $statusCode = 200
): JsonResponse
```

**Ejemplos:**

```php
return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
return $this->sendSuccess(null, 'Usuario eliminado exitosamente');
```

#### `sendError()`

```php
protected function sendError(
    string $message = 'Error en la operación',
    int $statusCode = 400,
    array $errors = []
): JsonResponse
```

**Ejemplos:**

```php
return $this->sendError('Usuario no encontrado', 404);
return $this->sendError('Datos inválidos', 422, $validator->errors()->toArray());
```

#### `sendPaginated()`

```php
protected function sendPaginated(LengthAwarePaginator $paginator): JsonResponse
```

**Ejemplos:**

```php
$users = $this->userService->list($filters);
return $this->sendPaginated($users);
```

### ✅ HACER

- Usar métodos helper del Controller base
- Consistencia en mensajes en español
- Códigos HTTP apropiados (200, 201, 204, 400, 401, 403, 404, 422, 500)

### ❌ NO HACER

- No usar `response()->json()` directamente
- No usar helpers estáticos como `ApiResponse::`
- No mezclar formatos de respuesta

```php
// ❌ Incorrecto
return response()->json([
    'success' => true,
    'data' => $user
], 200);

// ❌ Incorrecto
return ApiResponse::success($user, 'Usuario creado');

// ✅ Correcto
return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
```

---

## 7. Documentación

### Atributos de Scramble

Usar atributos PHP 8 para documentar endpoints:

```php
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\BodyParameter;

/**
 * Listado de usuarios con filtros y paginación
 */
#[QueryParameter('search', description: 'Buscar por nombre o email', required: false, type: 'string', example: 'juan')]
#[QueryParameter('per_page', description: 'Elementos por página', required: false, type: 'integer', default: 20, example: 20)]
#[QueryParameter('page', description: 'Número de página', required: false, type: 'integer', default: 1, example: 1)]
public function index(Request $request): JsonResponse
{
    // ...
}
```

### PHPDoc

Incluir PHPDoc descriptivo para cada método:

```php
/**
 * Actualizar perfil del usuario autenticado.
 *
 * Permite actualizar datos personales y de perfil del usuario actual.
 * Los campos de ubicación (department_id, province_id, district_id)
 * se guardan en el JSON location del perfil.
 *
 * @param UpdateProfileRequest $request Datos validados del perfil
 * @return JsonResponse Usuario actualizado con perfil completo
 */
public function updateProfile(UpdateProfileRequest $request): JsonResponse
{
    // ...
}
```

---

## 8. Manejo de Errores

### ✅ HACER

- Confiar en el **manejo global de excepciones** para ModelNotFoundException, AuthorizationException, etc.
- Usar **try-catch** solo cuando se necesita lógica específica
- Registrar errores con `LogService` en operaciones críticas

```php
// ✅ Correcto - confiar en global handler
public function show(string $id): JsonResponse
{
    $user = $this->userService->find($id); // Lanza ModelNotFoundException si no existe
    $this->authorize('view', $user);
    return $this->sendSuccess($user, 'Usuario obtenido exitosamente');
}

// ✅ Correcto - try-catch cuando hay lógica específica
public function login(LoginRequest $request): JsonResponse
{
    try {
        $result = $this->authService->authenticate($request->validated());
        return $this->sendSuccess($result, 'Login exitoso');
    } catch (TooManyAttemptsException $e) {
        LogService::warning('Intentos de login excedidos', [
            'ip' => $request->ip(),
            'identity' => $request->identity_document,
        ]);
        return $this->sendError($e->getMessage(), 429);
    }
}
```

### ❌ NO HACER

- No hacer verificaciones manuales de existencia con `if (!$model)`
- No retornar errores genéricos sin logging en operaciones críticas

```php
// ❌ Incorrecto
$user = User::find($id);
if (!$user) {
    return $this->sendError('Usuario no encontrado', 404);
}

// ✅ Correcto
$user = $this->userService->find($id); // Lanza ModelNotFoundException
```

---

## 9. Validación

### ✅ HACER

- Usar **Form Requests** para validación compleja
- Type hint con la clase Request específica

```php
// ✅ Correcto
public function store(StoreUserRequest $request): JsonResponse
{
    $validated = $request->validated();
    $user = $this->userService->create($validated);
    return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
}
```

### ⚠️ Excepciones

Para validaciones simples, usar `$request->validate()`:

```php
// ⚠️ Aceptable para validaciones muy simples
public function search(Request $request): JsonResponse
{
    $validated = $request->validate([
        'q' => 'required|string|min:3',
    ]);
    // ...
}
```

---

## 10. Type Hints y PHPDoc

### ✅ HACER

- **Siempre** usar type hints en:
  - Parámetros de métodos
  - Retorno de métodos
  - Propiedades de clase (PHP 7.4+)

```php
// ✅ Correcto
protected UserService $userService;

public function __construct(UserService $userService)
{
    $this->userService = $userService;
}

public function index(Request $request): JsonResponse
{
    // ...
}
```

### PHPStan Compliance

Para evitar errores de PHPStan:

```php
// ✅ Correcto - especificar tipos de arrays
/**
 * @param array<string, mixed> $data
 * @return array<int, string>
 */
protected function processData(array $data): array
{
    // ...
}

// ✅ Correcto - verificar tipos antes de usar
$user = auth()->user();
if (!$user instanceof User) {
    return $this->sendError('Usuario no autenticado', 401);
}
```

---

## 11. Nomenclatura

### Nombres de Métodos

#### CRUD Estándar:

- `index()` - Listado paginado
- `show(string $id)` - Ver un recurso
- `store(StoreRequest $request)` - Crear recurso
- `update(UpdateRequest $request, string $id)` - Actualizar recurso
- `destroy(string $id)` - Eliminar recurso

#### Métodos Personalizados:

Usar verbos descriptivos:

- `indexStudents()` - Listado específico de estudiantes
- `assignRoles(string $id)` - Asignar roles a usuario
- `updateProfile()` - Actualizar perfil del usuario autenticado
- `restore(string $id)` - Restaurar recurso eliminado

### Rutas y Controladores

```php
// ✅ Nombres descriptivos y agrupados
Route::prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('/students', [UserController::class, 'indexStudents']);
    Route::post('/', [UserController::class, 'store']);
});
```

---

## 12. Ejemplos

### Ejemplo Completo: UserController

```php
<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserController
 *
 * Gestión de usuarios del sistema.
 * Incluye operaciones CRUD y gestión de roles.
 */
class UserController extends Controller
{
    protected UserService $userService;

    protected ?string $model = User::class;
    protected ?string $resource = UserResource::class;
    protected array $allowedRelations = ['roles', 'permissions', 'profile'];
    protected array $allowedSortFields = ['name', 'email', 'created_at'];
    protected array $allowedFilterFields = ['email'];

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Listado de usuarios con filtros y paginación.
     */
    #[QueryParameter('search', description: 'Buscar por nombre o email', required: false, type: 'string')]
    #[QueryParameter('per_page', description: 'Elementos por página', required: false, type: 'integer', default: 20)]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = [
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page', 20),
        ];

        $users = $this->userService->list($filters);

        return $this->sendPaginated($users);
    }

    /**
     * Obtener detalles de un usuario específico.
     */
    public function show(string $id): JsonResponse
    {
        $user = $this->userService->find($id);
        $this->authorize('view', $user);

        return $this->sendSuccess($user, 'Usuario obtenido exitosamente');
    }

    /**
     * Crear un nuevo usuario.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $this->userService->create(
            $request->validated(),
            $request->input('role_ids', [])
        );

        return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
    }

    /**
     * Actualizar un usuario existente.
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->userService->find($id);
        $this->authorize('update', $user);

        $updated = $this->userService->update($id, $request->validated());

        return $this->sendSuccess($updated, 'Usuario actualizado exitosamente');
    }

    /**
     * Eliminar un usuario (soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $user = $this->userService->find($id);
        $this->authorize('delete', $user);

        $this->userService->delete($id);

        return $this->sendSuccess(null, 'Usuario eliminado exitosamente');
    }
}
```

### Ejemplo Completo: AuthController

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Services\AuthService;
use App\Services\LogService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;

/**
 * AuthController
 *
 * Gestión de autenticación y autorización.
 * Maneja login, registro, logout y renovación de tokens JWT.
 */
class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Autenticar usuario y generar tokens.
     */
    #[BodyParameter('identity_document', 'Documento de identidad', required: true, example: '12345678')]
    #[BodyParameter('password', 'Contraseña', required: true, example: 'password123')]
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->authenticate(
                $request->validated(),
                $request->ip()
            );

            if (!$result) {
                return $this->sendError('Credenciales inválidas', 401);
            }

            return $this->sendSuccess(
                new AuthResource($result),
                'Login exitoso'
            );
        } catch (\Exception $e) {
            LogService::error('Error en login', [
                'error' => $e->getMessage(),
            ]);
            return $this->sendError('Error al procesar el login', 500);
        }
    }

    /**
     * Cerrar sesión y revocar token actual.
     */
    public function logout(): JsonResponse
    {
        try {
            $this->authService->revokeToken();
            return $this->sendSuccess(null, 'Logout exitoso');
        } catch (\Exception $e) {
            LogService::error('Error en logout', [
                'error' => $e->getMessage(),
            ]);
            return $this->sendError('Error al procesar el logout', 500);
        }
    }
}
```

---

## Resumen de Buenas Prácticas

### ✅ SIEMPRE

1. Extender `Controller` base
2. Inyectar Services en constructor
3. Usar `$this->authorize()` para permisos
4. Usar métodos helper: `sendSuccess()`, `sendError()`, `sendPaginated()`
5. Type hints en todo: propiedades, parámetros, retornos
6. Form Requests para validación
7. Documentar con attributes de Scramble
8. PHPDoc descriptivos
9. Delegar lógica de negocio a Services
10. Logging con `LogService` en operaciones críticas

### ❌ NUNCA

1. Crear controladores sin extender Controller base
2. Usar `response()->json()` directamente
3. Verificar permisos manualmente con `hasPermission()`
4. Incluir lógica de negocio en controladores
5. Hacer consultas Eloquent complejas en controladores
6. Mezclar formatos de respuesta
7. Omitir type hints
8. Usar helpers estáticos como `ApiResponse::`

---

**Última actualización:** 2026-03-16  
**Versión:** 1.0.0

# Architecture — APYGG

Documentación de la arquitectura del boilerplate API con Laravel 12.

## Visión General

APYGG sigue una arquitectura **Layered Architecture** con separación clara de responsabilidades.

```
┌─────────────────────────────────────────────────────────┐
│                    CLIENT REQUEST                        │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                    MIDDLEWARES                            │
│  RateLimit → CORS → SecurityHeaders → Auth → Tenant     │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                   CONTROLLERS                            │
│         (HTTP Layer - Validación y Routing)              │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                    SERVICES                              │
│        (Business Logic - Lógica de negocio)             │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                  REPOSITORIES                            │
│           (Data Access - Acceso a datos)                │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                 ELOQUENT MODELS                          │
│          (Persistence - Persistencia)                   │
└─────────────────────────────────────────────────────────┘
```

## Componentes Principales

### 1. HTTP Layer

#### Controllers

Todos los controllers extienden `BaseController` que proporciona:

```
app/Http/Controllers/Controller.php
├── index()      → Listar con paginación
├── show()       → Mostrar un registro
├── store()      → Crear registro
├── update()     → Actualizar registro
├── destroy()    → Eliminar registro
├── sendSuccess() → Respuesta exitosa
├── sendError()  → Respuesta de error
└── sendPaginated() → Respuesta paginada
```

#### Form Requests

Validación de entrada a través de `BaseFormRequest`:

```
app/Http/Requests/BaseFormRequest.php
├── Auto-sanitización (trim, empty→null)
├── Mensajes en español por defecto
├── Métodos auxiliares: uuidRule(), emailRule(), etc.
└── Soporte para getCustomMessages() y getCustomAttributes()
```

#### Resources

Transformación de datos de salida con `BaseResource`:

```
app/Http/Resources/BaseResource.php
├── getBaseFields()     → Campos base del modelo
├── includeWhenLoaded() → Incluir relaciones cargadas
├── whenExists()        → Incluir si existe
├── formatDate()        → Formateo de fechas
└── includeTimestamps() → Incluir timestamps
```

### 2. Service Layer

Los servicios contienen la lógica de negocio. Cada servicio:

- Recibe DTOs como entrada (no arrays)
- Retorna DTOs o modelos tipados
- No accede directamente a la base de datos
- Usa Repositories para acceso a datos

```
app/Services/
├── AuthService.php           → Autenticación JWT
├── UserService.php           → CRUD de usuarios
├── TokenService.php          → Gestión de tokens
├── ApiKeyService.php         → Gestión de API keys
├── WebhookService.php        → Gestión de webhooks
├── FileService.php           → Gestión de archivos
├── CacheService.php          → Gestión de cache
├── LogService.php            → Gestión de logs
├── SecurityService.php       → Seguridad y rate limiting
├── NotificationService.php   → Notificaciones FCM
├── PasswordService.php       → Gestión de contraseñas
├── RoleService.php           → Gestión de roles
├── PermissionService.php     → Gestión de permisos
└── SocialAuthService.php     → OAuth social login
```

### 3. Repository Pattern

Los repositories desacoplan el acceso a datos de la lógica de negocio.

```
app/Repositories/
├── RepositoryInterface.php   → Contrato base
├── UserRepository.php
├── RoleRepository.php
├── PermissionRepository.php
├── ApiKeyRepository.php
├── WebhookRepository.php
└── FileRepository.php
```

Ejemplo de uso en Service:

```php
public function __construct(
    protected UserRepository $userRepository,
    protected FileRepository $fileRepository
) {}

public function findById(string $id): ?User
{
    return $this->userRepository->findById($id);
}
```

### 4. Contracts / Interfaces

Interfaces que definen contratos de servicios para inyección de dependencias:

```
app/Contracts/
├── AuthServiceInterface.php
├── UserServiceInterface.php
├── TokenServiceInterface.php
├── ApiKeyServiceInterface.php
├── WebhookServiceInterface.php
├── FileServiceInterface.php
├── CacheServiceInterface.php
├── LogServiceInterface.php
├── NotificationServiceInterface.php
├── SecurityServiceInterface.php
├── PermissionServiceInterface.php
└── RoleServiceInterface.php
```

### 5. DTOs (Data Transfer Objects)

Objetos tipados para transferencia de datos entre capas:

```
app/DTOs/
├── LoginDTO.php
├── RegisterDTO.php
├── CreateUserDTO.php
├── UpdateUserDTO.php
├── CreateApiKeyDTO.php
└── CreateWebhookDTO.php
```

### 6. Enums

Enumeraciones para valores fijos:

```
app/Enums/
├── UserStatusEnum.php    (active, inactive, banned)
├── RoleEnum.php          (admin, user, guest)
├── ApiKeyScopeEnum.php
├── WebhookEventEnum.php
├── LogActionEnum.php     (created, updated, deleted, restored)
└── FileTypeEnum.php
```

### 7. Models

Modelos Eloquent con base class compartida:

```
app/Models/
├── Model.php              → BaseModel abstract (UUID, SoftDeletes)
├── User.php               → Extends Authenticatable + JWTSubject
├── ApiKey.php
├── File.php
├── Webhook.php
├── WebhookDelivery.php
├── Role.php
├── Permission.php
├── Settings.php
├── DeviceToken.php
├── JwtBlacklist.php
└── Logs/
    ├── ApiLog.php
    ├── ActivityLog.php
    └── SecurityLog.php
```

## Flujos Principales

### Flujo de Autenticación

```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│  Client  │───▶│  Login   │───▶│  Auth    │───▶│  Token   │
│  Request │    │  Request │    │  Service │    │  Service │
└──────────┘    └──────────┘    └──────────┘    └──────────┘
                     │               │               │
                     ▼               ▼               ▼
                 Validación    Verificar      Generar JWT
                 de datos      credenciales   + Refresh Token
                                              
┌──────────┐    ┌──────────┐    ┌──────────┐
│  Client  │◀───│  JWT     │◀───│  User    │
│  Response│    │  Token   │    │  Model   │
└──────────┘    └──────────┘    └──────────┘
```

### Flujo de Request Lifecycle

```
Request → Middleware Stack:
  ├── RateLimitMiddleware (límite de requests)
  ├── CorsMiddleware (origins permitidos)
  ├── SecurityHeadersMiddleware (headers de seguridad)
  ├── IpWhitelistMiddleware (whitelist de IPs)
  ├── AuthMiddleware (autenticación JWT)
  └── TenantMiddleware (resolución de tenant)
         │
         ▼
    Controller
    ├── Validación (FormRequest)
    ├── Llamada a Service
    └── Transformación (Resource)
         │
         ▼
    Service
    ├── Lógica de negocio
    ├── Validación de reglas
    └── Llamada a Repository
         │
         ▼
    Repository
    ├── Query Builder / Eloquent
    └── Retorno de datos
```

### Flujo de Webhooks

```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│  Event   │───▶│ Webhook  │───▶│  Queue   │───▶│  HTTP    │
│ Trigger  │    │ Service  │    │  Job     │    │  Post    │
└──────────┘    └──────────┘    └──────────┘    └──────────┘
                                      │               │
                                      ▼               ▼
                                 Reintentos      Delivery Log
                                 automáticos     (éxito/error)
```

## Decisiones Arquitectónicas (ADRs)

### ADR-001: Repository Pattern

**Decisión**: Usar Repository Pattern para acceso a datos.

**Contexto**: Los services accedían directamente a Eloquent, lo que dificultaba testing y cambio de fuente de datos.

**Consecuencias**:
- ✅ Desacoplamiento de la lógica de negocio del ORM
- ✅ Fácil mocking para tests unitarios
- ✅ Posibilidad de cambiar de Eloquent a otra fuente
- ⚠️ Capa adicional de abstracción
- ⚠️ Más código a mantener

### ADR-002: DTOs en vez de Arrays

**Decisión**: Usar DTOs tipados en vez de arrays asociativos en Services.

**Contexto**: Arrays sueltos no proporcionan autocompletado, type safety ni validación.

**Consecuencias**:
- ✅ Type safety y autocompletado
- ✅ Validación centralizada
- ✅ Documentación implícita del código
- ⚠️ Más clases a crear

### ADR-003: Enums en vez de Strings

**Decisión**: Usar PHP 8.1+ Enums para valores fijos.

**Contexto**: Strings hardcodeados son propensos a errores de tipeo y difíciles de mantener.

**Consecuencias**:
- ✅ Type safety
- ✅ Autocompletado
- ✅ Refactoring seguro
- ✅ Validez garantizada

### ADR-004: Base Classes

**Decisión**: Crear base classes (Model, Controller, FormRequest, Resource) con funcionalidad común.

**Contexto**: Código duplicado entre modelos, controllers, requests y resources.

**Consecuencias**:
- ✅ DRY (Don't Repeat Yourself)
- ✅ Comportamiento consistente
- ✅ Fácil extensión
- ⚠️ Dependencia fuerte a la base class

## Cómo Agregar un Nuevo Módulo

### Ejemplo: Agregar módulo "Products"

#### 1. Crear Migration

```bash
docker compose exec app php artisan make:migration create_products_table
```

```php
Schema::create('products', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->timestamps();
    $table->softDeletes();
});
```

#### 2. Crear Model

```php
namespace App\Models;

class Product extends Model
{
    protected $fillable = ['name', 'description', 'price'];
    
    protected $casts = [
        'price' => 'decimal:2',
    ];
}
```

#### 3. Crear DTOs

```php
namespace App\DTOs;

class CreateProductDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly float $price,
    ) {}
    
    public static function fromRequest(StoreProductRequest $request): self
    {
        return new self(
            name: $request->validated('name'),
            description: $request->validated('description'),
            price: $request->validated('price'),
        );
    }
}
```

#### 4. Crear Repository

```php
namespace App\Repositories;

use App\Models\Product;

class ProductRepository extends BaseRepository
{
    protected function model(): string
    {
        return Product::class;
    }
}
```

#### 5. Crear Service

```php
namespace App\Services;

class ProductService
{
    public function __construct(
        protected ProductRepository $productRepository,
    ) {}
    
    public function create(CreateProductDTO $dto): Product
    {
        return $this->productRepository->create([
            'name' => $dto->name,
            'description' => $dto->description,
            'price' => $dto->price,
        ]);
    }
}
```

#### 6. Crear Form Requests

```bash
docker compose exec app php artisan make:request Products/StoreProductRequest
docker compose exec app php artisan make:request Products/UpdateProductRequest
```

#### 7. Crear Resource

```bash
docker compose exec app php artisan make:resource Products/ProductResource
```

#### 8. Crear Controller

```bash
docker compose exec app php artisan make:controller Products/ProductController
```

```php
namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Services\ProductService;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
    ) {}
    
    // index(), show(), store(), update(), destroy()
}
```

#### 9. Registrar Ruta

```php
// routes/api.php
Route::apiResource('products', ProductController::class);
```

#### 10. Crear Tests

```bash
docker compose exec app php artisan make:test Products/ProductTest
docker compose exec app php artisan make:test Products/ProductServiceTest
```

## Directorios de Configuración

```
config/
├── auth.php           → Configuración JWT y guards
├── cache.php          → Redis y cache stores
├── cors.php           → CORS policy
├── database.php       → PostgreSQL connections
├── jwt.php            → JWT Auth config
├── permission.php     → Spatie Permission config
├── queue.php          → Queue connections
├── rate-limiter.php   → Rate limiting rules
├── services.php       → External services
└── settings.php       → Application settings
```

## Referencias

- [Laravel 12 Documentation](https://laravel.com/docs/12)
- [Repository Pattern](https://martinfowler.com/eaaCatalog/repository.html)
- [Conventional Commits](https://www.conventionalcommits.org/)
- [Keep a Changelog](https://keepachangelog.com/)

# Estándares de codificación

Convenciones del boilerplate. El objetivo es que cualquier módulo nuevo se lea
igual que los que ya existen y que dos personas distintas produzcan el mismo
código para el mismo problema.

## Estructura de un módulo

```
app/
├── Http/
│   ├── Controllers/{Modulo}/
│   │   └── {Modulo}Controller.php
│   ├── Requests/{Modulo}/
│   │   ├── Store{Modulo}Request.php
│   │   └── Update{Modulo}Request.php
│   └── Resources/{Modulo}/
│       └── {Modulo}Resource.php
├── Models/
│   └── {Modulo}.php
├── Contracts/
│   └── {Modulo}ServiceInterface.php
└── Services/
    └── {Modulo}Service.php

routes/api/{modulo}.php   → se incluye con require desde routes/api.php
```

## Modelo

```php
<?php

namespace App\Models;

/**
 * App\Models\Report
 *
 * @property int $id
 * @property string $title
 */
class Report extends Model   // App\Models\Model, no Eloquent\Model
{
    protected $table = 'reports';

    protected $fillable = [
        'title',
        'user_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

**Reglas:**
- Heredar de `App\Models\Model` (trae soft deletes, casts de timestamps y scopes comunes).
- `use HasUuid` solo si el recurso necesita ID no adivinable (ver [id-strategy.md](id-strategy.md)).
- `snake_case` en tablas y columnas, `PascalCase` en clases.
- `$fillable` siempre explícito, nunca vacío ni `$guarded = []`.
- `$casts` para fechas, arrays y booleanos: sin ellos los Resources reciben strings.
- Relaciones con tipo de retorno declarado.

## Controller

Hay **una sola base**: `App\Http\Controllers\Controller` (abstracta, como la de Laravel). Trae autorización (`authorize()`), validación y las respuestas estándar (`sendSuccess`, `sendError`, `sendPaginated`, `perPage`), y **ninguna acción CRUD**: cada controller declara las suyas.

⚠️ Tipa siempre los parámetros de las acciones. Laravel solo inyecta parámetros con tipo de clase: `store($request)` recibe el primer parámetro de ruta (o nada) y la ruta da 500. Usa un **Form Request tipado** (`store(StoreReportRequest $request)`) y **route-model binding** o `findOrFail` para el recurso.

```php
<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Contracts\ReportServiceInterface;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportServiceInterface $reports
    ) {}

    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reports->create($request->validated());

        return $this->sendSuccess(new ReportResource($report), 'Reporte creado exitosamente', 201);
    }
}
```

**Reglas:**
- Un controller por módulo, en `Controllers/{Modulo}/`.
- Extiende siempre `Controller` (la única base) y declara tus acciones.
- Métodos REST: `index`, `store`, `show`, `update`, `destroy`.
- Type hints en parámetros y retorno; `JsonResponse` en escritura.
- La validación vive en Form Requests, nunca en el controller.
- La lógica vive en Services; el controller orquesta y responde.
- Inyecta la **interfaz** del servicio (`app/Contracts/`), no la implementación:
  el binding está en `ContractServiceProvider`.

Ver también [CONTROLLER_CONVENTIONS.md](CONTROLLER_CONVENTIONS.md).

## Crear un servicio nuevo (interfaz + clase + binding + inyección + test)

1. **Interfaz** en `app/Contracts/ReportServiceInterface.php`: solo los métodos
   que la app necesita (no detalles internos), con tipos y docblock.
2. **Clase** en `app/Services/ReportService.php` que la implementa. Eloquent
   directo; reglas de negocio con `BusinessLogicException::ruleViolation(...)`
   (422), `invalidState`/`operationNotAllowed`, o una excepción propia que
   extienda `ApiException`. Nada de `\Exception` genérica ni estado estático.
3. **Binding** en `ContractServiceProvider`:
   `$this->app->bind(ReportServiceInterface::class, ReportService::class);`
   (`bind` por defecto; `singleton` solo si no tiene estado y es costoso;
   `scoped` si guarda estado por petición. Nunca estado de petición en un `singleton`: Octane).
4. **Inyección** de la interfaz:
   ```php
   public function __construct(private readonly ReportServiceInterface $reports) {}
   ```
   En jobs, en `process(ReportServiceInterface $reports)`; en comandos, en
   `handle(...)`; en middlewares y listeners, por constructor.
5. **Registro en el arch test**: añade `ReportService => ReportServiceInterface` a
   `archServiciosConInterfaz()` en `tests/Unit/ArchTest.php`.
6. **Test**: unitario del servicio (`app(ReportServiceInterface::class)`) y
   feature del endpoint, con el camino denegado. Para sustituir una dependencia
   usa `$this->app->instance(Interfaz::class, $fake)` (ver `fakeDns()` en
   `tests/TestCase.php`).

**¿Repositorio?** Por defecto no: el servicio consulta con Eloquent (scopes en el
modelo para filtros reutilizables). Solo si una consulta compleja la comparten
varios servicios, crea `app/Repositories/XRepository` con su interfaz en
`app/Contracts/`, binding en el provider e inyéctala en esos servicios.

## Form Request

```php
<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\BaseFormRequest;

class StoreReportRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reports.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:reports,slug'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio',
        ];
    }
}
```

**Reglas:**
- Heredar de `BaseFormRequest`.
- `authorize()` siempre presente y real: `return true` solo si la ruta ya está
  protegida por middleware de permisos.
- Mensajes en español.
- `snake_case` en los nombres de campos.

## Service

```php
<?php

namespace App\Services;

use App\Contracts\ReportServiceInterface;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

class ReportService implements ReportServiceInterface
{
    public function create(array $data): Report
    {
        return DB::transaction(function () use ($data) {
            $report = Report::create($data);
            // efectos secundarios: eventos, notificaciones, cache…
            return $report;
        });
    }
}
```

**Reglas:**
- Un service por módulo, con su interfaz en `app/Contracts/`.
- `DB::transaction` en toda operación de escritura con más de un paso.
- Devuelve modelos, colecciones o arrays, nunca `JsonResponse`.

## Rutas

```php
// routes/api/reports.php

// Públicas
Route::get('/reports', [ReportController::class, 'index']);

// Autenticadas
Route::middleware('auth:api')->group(function () {
    Route::post('/reports', [ReportController::class, 'store']);
    Route::put('/reports/{report}', [ReportController::class, 'update']);
});

// Con permiso explícito
Route::middleware(['auth:api', 'permission:reports.delete,api'])
    ->delete('/reports/{report}', [ReportController::class, 'destroy']);

// Detrás de feature flag
Route::middleware('feature:beta_reports')
    ->get('/reports/beta', [ReportController::class, 'beta']);

// Autenticación opcional (el endpoint sirve a invitados y a usuarios)
Route::middleware('auth.optional')
    ->post('/reports/preview', [ReportController::class, 'preview']);
```

**Reglas:**
- **No existe el prefijo `/api/`**: las rutas cuelgan de la raíz
  (ver [api-routes-conventions.md](api-routes-conventions.md)).
- Cada módulo tiene su archivo en `routes/api/` incluido desde `routes/api.php`.
- Middleware disponibles: `auth:api`, `permission:`, `role:`, `role_or_permission:`
  (spatie/laravel-permission, siempre con el permiso exacto y el guard:
  `permission:reports.delete,api`; ver [permissions.md](permissions.md)),
  `api-key-scope:`, `ip.whitelist`, `feature:`, `auth.optional`.

## Migración

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
```

**Reglas:**
- **PostgreSQL exclusivamente**: `$table->jsonb()` en lugar de `json()` (un test
  falla si queda una columna `json`), `ILIKE` en lugar de `LIKE` para búsquedas
  insensibles, nada de sintaxis MySQL. Una migración ya aplicada no se reescribe:
  crea otra (ver `2026_11_19_000040_convert_json_columns_to_jsonb`).
- `constrained()` con `cascadeOnDelete()` o `restrictOnDelete()` explícito.
- `softDeletes()` si el modelo hereda del `Model` base.
- Índices en las columnas por las que se filtra u ordena.
- `down()` siempre implementado.

## Naming conventions

| Elemento | Convención | Ejemplo |
|----------|------------|---------|
| Tabla | snake_case plural | `report_items` |
| Columna | snake_case | `created_at` |
| Modelo | PascalCase singular | `ReportItem` |
| Controller | PascalCase | `ReportItemController` |
| Service | PascalCase | `ReportItemService` |
| Interfaz | PascalCase + `Interface` | `ReportItemServiceInterface` |
| Request | PascalCase | `StoreReportItemRequest` |
| Método | camelCase | `getReportItems()` |
| Variable | camelCase | `$reportItem` |
| Constante | UPPER_SNAKE_CASE | `MAX_ITEMS` |
| Permiso | punto, minúsculas | `reports.create` |

## Respuestas JSON

> ⚠️ **Regla de oro**: las respuestas pasan **siempre** por los métodos del
> Controller base (`sendSuccess`, `sendError`, `sendPaginated`) o por
> `ApiResponse` en middlewares y handlers. Nunca `response()->json()` a mano en
> un controller: rompe el envelope que el cliente espera.

```php
return $this->sendSuccess($report, 'Reporte obtenido exitosamente');       // 200
return $this->sendSuccess($report, 'Reporte creado exitosamente', 201);    // 201
return $this->sendError('No se pudo crear el reporte', 422, $errors);      // 422
return $this->sendPaginated($reports);                                     // listado
```

Envelope estándar:

```json
{ "success": true, "message": "...", "data": { }, "meta": { }, "links": { } }
```

Los errores siguen RFC 7807 vía `ApiResponse::rfc7807()` — lo produce el
handler global de `bootstrap/app.php`, no hay que construirlo a mano.

Ver [api-response-structure.md](api-response-structure.md) y
[error-handling.md](error-handling.md).

## Regla N+1 (eager loading obligatorio)

Un listado que accede a una relación por cada fila genera N+1 queries: 100
registros → 400+ queries y varios segundos de respuesta.

1. **Todo listado usa `with([...])`** con todas las relaciones que el Resource
   serializa. Nunca confíes en el lazy loading implícito.
2. **Conteos con `withCount('relacion')`** — una query agregada. Nunca
   `$model->relacion()->count()` dentro de un loop o de un Resource.
3. **El Resource solo toca relaciones cargadas**: usa `whenLoaded()` (ya
   encapsulado en `BaseResource`).
4. **Resources ligeros por contexto**: si un endpoint no necesita media docena
   de relaciones, crea un Resource específico en vez de reutilizar el completo.
5. **Verifica antes de entregar** un endpoint de listado: cuenta las queries.
6. **Meta**: cualquier listado responde en < 500 ms con 20k+ filas y no más de
   ~20 queries por request.

### Cómo detectar N+1

```php
// make sh  →  php artisan tinker
DB::enableQueryLog();
$reports = Report::with('items')->paginate(100);
dump(count(DB::getQueryLog()));   // si crece con per_page, hay N+1
```

Un listado sano genera: 1 (paginación) + 1 (count) + 1 por relación cargada.

```php
// ✅ Correcto
Report::query()
    ->withCount('items')
    ->with(['user', 'items' => fn ($q) => $q->with('state')])
    ->paginate(100);

// ❌ Incorrecto
foreach ($reports as $report) {
    $report->items()->count();   // 1 query por fila
    $report->user;               // otra por fila
}
```

### Prevención

```php
// AppServiceProvider::boot(), solo en local/dev
Model::preventLazyLoading(app()->isLocal());
```

Así una relación no cargada falla de forma visible en desarrollo en lugar de
degradar el rendimiento en producción de forma silenciosa.

## Errores comunes a evitar

1. **No ejecutar `php artisan` en el host** → `make art cmd="..."` (todo corre en Docker).
2. **No olvidar `make check`** (pint + phpstan) después de cada cambio PHP.
3. **No usar sintaxis MySQL** → PostgreSQL exclusivamente.
4. **No usar el prefijo `/api/`** en rutas.
5. **No poner lógica de negocio en controllers** → Services.
6. **No validar en el controller** → Form Requests.
7. **No paginar en memoria** → `->paginate()` en SQL. Nunca `->get()` seguido de
   `array_slice()` o `->forPage()`: traer 20k filas para mostrar 15 es la causa
   más común de endpoints de varios segundos.
8. **No devolver `response()->json()` a mano** → métodos del Controller base.
9. **No acceder a relaciones sin cargar** → `with()` / `withCount()`.
10. **No dejar parámetros sin tipo en las acciones** (`store($request)`) → tipa el
    Form Request; sin tipo Laravel no lo inyecta y la ruta da 500.
11. **No estrechar la firma de los scopes del `Model` base** → `scopeActive($query)`
    está sin tipar; un `scopeActive(Builder $query): Builder` en el hijo revienta
    por el mismo motivo.

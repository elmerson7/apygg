# Feature Flags - Ejemplos de Uso

## Configuración de Variables de Entorno

Agrega estas variables a tu archivo `.env` (raíz del proyecto):

```env
# Feature Flags
FEATURE_ADVANCED_SEARCH=false
FEATURE_EXPORT_USERS=false
FEATURE_TWO_FACTOR_AUTH=false
FEATURE_DEBUG_ENDPOINTS=false
FEATURE_BETA_API_FEATURES=false
FEATURE_REAL_TIME_NOTIFICATIONS=false
FEATURE_ADVANCED_LOGGING=true
FEATURE_RATE_LIMITING_ADAPTIVE=false
```

**Nota:** Las variables en `env/dev.env` son solo para Docker Compose. Las variables de Laravel (incluyendo Feature Flags) van en `.env` en la raíz.

---

## Ejemplos de Uso en Código

### 1. En un Controller

```php
namespace App\Http\Controllers\Api;

use App\Helpers\Feature;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query();
        
        // Aplicar búsqueda avanzada solo si está habilitada
        if (Feature::enabled('advanced_search')) {
            $users = $this->applyAdvancedFilters($users);
        }
        
        return UserResource::collection($users->paginate());
    }
    
    public function export()
    {
        // Verificar que la feature esté habilitada
        if (Feature::disabled('export_users')) {
            return response()->json([
                'error' => 'Esta funcionalidad no está disponible'
            ], 403);
        }
        
        return $this->generateExport();
    }
}
```

### 2. En Routes

```php
// routes/api.php

use App\Helpers\Feature;

Route::middleware('auth')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    
    // Endpoint solo disponible si la feature está activada
    if (Feature::enabled('export_users')) {
        Route::get('/users/export', [UserController::class, 'export']);
    }
    
    // Endpoints de debug solo en desarrollo
    if (Feature::enabled('debug_endpoints')) {
        Route::prefix('debug')->group(function () {
            Route::get('/info', [DebugController::class, 'info']);
            Route::get('/cache', [DebugController::class, 'cache']);
        });
    }
});
```

### 3. En Middleware

```php
namespace App\Http\Middleware;

use App\Helpers\Feature;
use Closure;
use Illuminate\Http\Request;

class CheckFeatureFlag
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        if (Feature::disabled($feature)) {
            return response()->json([
                'error' => 'Esta funcionalidad no está disponible actualmente'
            ], 403);
        }
        
        return $next($request);
    }
}
```

**Registro en `app/Http/Kernel.php`:**

```php
protected $middlewareAliases = [
    // ...
    'feature' => \App\Http\Middleware\CheckFeatureFlag::class,
];
```

**Uso en routes:**

```php
Route::get('/beta/endpoint', [BetaController::class, 'index'])
    ->middleware('feature:beta_api_features');
```

### 4. En Service Classes

```php
namespace App\Modules\Users\Services;

use App\Helpers\Feature;

class UserService
{
    public function search(array $filters)
    {
        if (Feature::enabled('advanced_search')) {
            return $this->advancedSearch($filters);
        }
        
        return $this->basicSearch($filters);
    }
    
    protected function advancedSearch(array $filters)
    {
        // Lógica de búsqueda avanzada
    }
    
    protected function basicSearch(array $filters)
    {
        // Lógica de búsqueda básica
    }
}
```

### 5. En Blade Views (si usas vistas)

```blade
@if(Feature::enabled('new_dashboard'))
    @include('dashboard.v2')
@else
    @include('dashboard.v1')
@endif
```

### 6. Obtener información de features

```php
use App\Helpers\Feature;

// Verificar si está habilitada
if (Feature::enabled('advanced_search')) {
    // ...
}

// Obtener descripción
$description = Feature::description('advanced_search');
// Retorna: "Búsqueda avanzada con filtros múltiples y autocompletado"

// Obtener todas las features
$allFeatures = Feature::all();
// Retorna: [
//     'advanced_search' => ['enabled' => false, 'description' => '...'],
//     'export_users' => ['enabled' => false, 'description' => '...'],
//     ...
// ]

// Limpiar cache (útil en tests)
Feature::clearCache();
```

---

## Flujo de Trabajo Recomendado

### 1. Desarrollo de Nueva Feature

```php
// Desarrollas la feature con flag desactivado
if (Feature::enabled('new_feature')) {
    // código nuevo
} else {
    // código antiguo (fallback)
}
```

### 2. Testing en Desarrollo

```env
# .env (desarrollo)
FEATURE_NEW_FEATURE=true
```

### 3. Deploy a Producción (flag desactivado)

```env
# .env (producción)
FEATURE_NEW_FEATURE=false
```

El código nuevo está desplegado pero no activo. Si algo falla, no afecta a usuarios.

### 4. Activación Gradual

```env
# Activas para todos
FEATURE_NEW_FEATURE=true
```

### 5. Monitoreo

- Revisa logs y métricas
- Si todo va bien → la feature queda activa
- Si hay problemas → desactivas el flag (rollback rápido)

### 6. Limpieza (después de estabilizar)

Una vez que la feature es estable (1-2 meses), eliminas el flag y el código antiguo:

```php
// Antes (con flag)
if (Feature::enabled('new_feature')) {
    // código nuevo
} else {
    // código antiguo
}

// Después (sin flag)
// código nuevo (solo queda este)
```

---

## Testing

### En Tests Unitarios

```php
use App\Helpers\Feature;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    public function test_advanced_search_when_feature_disabled()
    {
        // Asegurar que la feature está desactivada
        Feature::clearCache();
        config(['features.advanced_search.enabled' => false]);
        
        $response = $this->getJson('/api/users?search=test');
        
        $response->assertOk();
        // Verificar que no se aplican filtros avanzados
    }
    
    public function test_advanced_search_when_feature_enabled()
    {
        // Activar la feature
        Feature::clearCache();
        config(['features.advanced_search.enabled' => true]);
        
        $response = $this->getJson('/api/users?search=test');
        
        $response->assertOk();
        // Verificar que se aplican filtros avanzados
    }
}
```

---

## Mejores Prácticas

1. ✅ **Usa flags para features nuevas o experimentales**
2. ✅ **Mantén código de fallback** mientras la feature se estabiliza
3. ✅ **Documenta cada feature** en `config/features.php`
4. ✅ **Limpia flags antiguos** después de estabilizar (1-2 meses)
5. ❌ **No uses flags para bugs fixes** (arregla directamente)
6. ❌ **No uses flags para cambios menores** de UI/texto
7. ❌ **No abuses de flags** - solo cuando realmente necesites control

---

## Troubleshooting

### El flag no funciona

1. Verifica que la variable esté en `.env`:
   ```bash
   grep FEATURE_ADVANCED_SEARCH .env
   ```

2. Limpia el cache de configuración:
   ```bash
   php artisan config:clear
   ```

3. Limpia el cache de la clase Feature:
   ```php
   Feature::clearCache();
   ```

### El flag cambió pero no se refleja

- Los cambios en `.env` requieren `php artisan config:clear`
- O reinicia el servidor/contenedor

---

## Referencias

- [Documentación de migración a BD](./features-migration-to-database.md)
- [TASKS.md - Fase 8](../../TASKS.md#fase-8-feature-flags-semana-7)

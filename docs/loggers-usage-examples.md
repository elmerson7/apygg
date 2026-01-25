# Loggers Especializados - Ejemplos de Uso

## Índice

1. [ActivityLogger](#activitylogger)
2. [AuthLogger](#authlogger)
3. [SecurityLogger](#securitylogger)
4. [ApiLogger](#apilogger)

---

## ActivityLogger

### Uso Manual

```php
use App\Infrastructure\Logging\Loggers\ActivityLogger;
use App\Models\User;

// Registrar creación
$user = User::create([...]);
ActivityLogger::logCreated($user);

// Registrar actualización
$oldValues = $user->getOriginal();
$user->update([...]);
ActivityLogger::logUpdated($user, $oldValues);

// Registrar eliminación
ActivityLogger::logDeleted($user);

// Registrar restauración (soft delete)
ActivityLogger::logRestored($user);
```

### Uso con Observers (Automático)

```php
// app/Observers/UserObserver.php
namespace App\Observers;

use App\Infrastructure\Logging\Loggers\ActivityLogger;
use App\Models\User;

class UserObserver
{
    public function created(User $user)
    {
        ActivityLogger::logCreated($user);
    }

    public function updated(User $user)
    {
        ActivityLogger::logUpdated($user, $user->getOriginal());
    }

    public function deleted(User $user)
    {
        ActivityLogger::logDeleted($user);
    }

    public function restored(User $user)
    {
        ActivityLogger::logRestored($user);
    }
}

// Registrar en AppServiceProvider
use App\Models\User;
use App\Observers\UserObserver;

public function boot()
{
    User::observe(UserObserver::class);
}
```

### Excluir campos sensibles adicionales

```php
ActivityLogger::excludeFields(['credit_card', 'ssn', 'bank_account']);
```

---

## AuthLogger

### Login Exitoso

```php
use App\Infrastructure\Logging\Loggers\AuthLogger;

// En LoginController
public function login(Request $request)
{
    if (Auth::attempt($credentials)) {
        $user = Auth::user();
        
        // Registrar login exitoso
        AuthLogger::logLoginSuccess($user);
        
        return response()->json(['token' => $token]);
    }
    
    // Login fallido (ver abajo)
}
```

### Login Fallido

```php
// En LoginController
public function login(Request $request)
{
    if (!Auth::attempt($credentials)) {
        // Registrar intento fallido
        AuthLogger::logLoginFailure(
            $request->email,
            $request->ip(),
            $request->userAgent(),
            'Invalid credentials'
        );
        
        return response()->json(['error' => 'Invalid credentials'], 401);
    }
}
```

### Cambio de Contraseña

```php
// En PasswordController
public function changePassword(Request $request)
{
    $user->update(['password' => Hash::make($request->password)]);
    
    AuthLogger::logPasswordChanged($user);
    
    return response()->json(['message' => 'Password changed']);
}
```

### Revocación de Token

```php
// En AuthController
public function logout(Request $request)
{
    $tokenId = $request->user()->currentAccessToken()->id;
    
    $request->user()->tokens()->where('id', $tokenId)->delete();
    
    AuthLogger::logTokenRevoked($request->user(), $tokenId);
    
    return response()->json(['message' => 'Logged out']);
}
```

### Detección de Actividad Sospechosa

```php
// Verificar si hay actividad sospechosa
if (AuthLogger::hasSuspiciousActivity($ipAddress, $email)) {
    // Bloquear IP o enviar alerta
    SecurityLogger::logSuspiciousActivity(
        'Multiple failed login attempts',
        null,
        ['ip' => $ipAddress, 'email' => $email]
    );
}

// Obtener número de intentos fallidos
$attempts = AuthLogger::getFailedAttempts($ipAddress, $email);
if ($attempts >= 5) {
    // Bloquear cuenta
}

// Limpiar contador después de login exitoso
AuthLogger::clearFailedAttempts($ipAddress, $email);
```

---

## SecurityLogger

### Permiso Denegado

```php
use App\Infrastructure\Logging\Loggers\SecurityLogger;

// En un middleware o policy
if (!$user->can('delete-post')) {
    SecurityLogger::logPermissionDenied(
        $user,
        'delete-post',
        "post-{$post->id}"
    );
    
    abort(403);
}
```

### Actividad Sospechosa

```php
// Detectar actividad sospechosa
SecurityLogger::logSuspiciousActivity(
    'Unusual API usage pattern detected',
    $user,
    [
        'requests_per_minute' => 100,
        'threshold' => 50,
    ]
);
```

### Bloqueo/Desbloqueo de Cuenta

```php
// Bloquear cuenta
$user->update(['locked_at' => now()]);
SecurityLogger::logAccountLocked($user, 'Multiple failed login attempts', $admin);

// Desbloquear cuenta
$user->update(['locked_at' => null]);
SecurityLogger::logAccountUnlocked($user, $admin);
```

### Evento Personalizado

```php
SecurityLogger::logEvent(
    SecurityLog::EVENT_SUSPICIOUS_ACTIVITY,
    $user,
    [
        'description' => 'Unusual data access pattern',
        'resource' => 'sensitive-data',
    ]
);
```

---

## ApiLogger

### Uso con Middleware (Automático)

```php
// app/Http/Middleware/LogApiRequests.php
namespace App\Http\Middleware;

use App\Infrastructure\Logging\Loggers\ApiLogger;
use App\Infrastructure\Services\LogService;
use Closure;
use Illuminate\Http\Request;

class LogApiRequests
{
    public function handle(Request $request, Closure $next)
    {
        // Establecer trace ID si no existe
        if (!$request->header('X-Trace-ID')) {
            $traceId = LogService::getTraceId();
            $request->headers->set('X-Trace-ID', $traceId);
        }
        
        $startTime = microtime(true);
        $response = $next($request);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        // Registrar request/response
        ApiLogger::logRequest($request, $response, $responseTime);
        
        return $response;
    }
}

// Registrar en app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        // ...
        \App\Http\Middleware\LogApiRequests::class,
    ],
];
```

### Uso Manual

```php
use App\Infrastructure\Logging\Loggers\ApiLogger;

// En un controller o middleware
$response = $this->processRequest($request);
$responseTime = 150; // ms

ApiLogger::logRequest($request, $response, $responseTime);
```

### Excluir Rutas

```php
// Excluir rutas específicas del logging
ApiLogger::excludePaths(['webhooks', 'cron', 'internal']);
```

### Excluir Headers

```php
// Excluir headers sensibles adicionales
ApiLogger::excludeHeaders(['x-custom-token', 'x-secret-key']);
```

---

## Ejemplos de Integración Completa

### En LoginController

```php
namespace App\Http\Controllers\Auth;

use App\Infrastructure\Logging\Loggers\AuthLogger;
use App\Infrastructure\Logging\Loggers\SecurityLogger;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            
            // Verificar si la cuenta está bloqueada
            if ($user->locked_at) {
                SecurityLogger::logPermissionDenied(
                    $user,
                    'login',
                    'account-locked'
                );
                return response()->json(['error' => 'Account locked'], 403);
            }
            
            // Registrar login exitoso
            AuthLogger::logLoginSuccess($user);
            
            // Limpiar intentos fallidos
            AuthLogger::clearFailedAttempts($request->ip(), $request->email);
            
            $token = $user->createToken('api-token')->plainTextToken;
            
            return response()->json(['token' => $token]);
        }

        // Login fallido
        AuthLogger::logLoginFailure(
            $request->email,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json(['error' => 'Invalid credentials'], 401);
    }
}
```

### En UserController con ActivityLogger

```php
namespace App\Http\Controllers\Api;

use App\Infrastructure\Logging\Loggers\ActivityLogger;
use App\Models\User;

class UserController extends Controller
{
    public function store(Request $request)
    {
        $user = User::create($request->validated());
        
        // El Observer debería hacer esto automáticamente, pero puedes hacerlo manualmente
        ActivityLogger::logCreated($user);
        
        return new UserResource($user);
    }

    public function update(Request $request, User $user)
    {
        $oldValues = $user->getOriginal();
        $user->update($request->validated());
        
        ActivityLogger::logUpdated($user, $oldValues);
        
        return new UserResource($user);
    }

    public function destroy(User $user)
    {
        ActivityLogger::logDeleted($user);
        $user->delete();
        
        return response()->json(['message' => 'User deleted']);
    }
}
```

---

## Notas Importantes

1. **Rendimiento**: Los loggers están diseñados para no interrumpir el flujo principal. Si fallan, solo se registra un error pero no se lanza excepción.

2. **Datos Sensibles**: Los loggers filtran automáticamente campos sensibles como passwords, tokens, etc.

3. **Trace ID**: El `trace_id` permite correlacionar logs relacionados (api_logs, error_logs, security_logs).

4. **Cache**: `AuthLogger` usa cache para detectar actividad sospechosa. Asegúrate de tener Redis o Memcached configurado.

5. **Middleware**: Los loggers están listos para usar en middleware, pero los middleware deben ser creados en la Fase 10.

---

## Próximos Pasos

- **Fase 9.4**: Configuración de canales de logging
- **Fase 10**: Creación de middleware para uso automático de los loggers

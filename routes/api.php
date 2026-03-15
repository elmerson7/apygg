<?php

use App\Http\Controllers\TestSentryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Todas las rutas aquí son para API únicamente.
| Rutas directas en la raíz (sin prefijo /api ni versión)
| Todas las respuestas son JSON (forzado por ForceJsonResponse middleware)
|
| Convención de prefijos:
|   - Sin prefijo  → rutas públicas (auth, search, files, webhooks)
|   - /user        → usuario autenticado (perfil, preferencias)
|   - /admin       → gestión administrativa (roles, permisos, api-keys)
|
| Estructura modular: cada módulo tiene su archivo en routes/api/
|
*/

// Ruta raíz: información de la API
Route::get('/', function () {
    $broadcastingEnabled = config('broadcasting.default') !== 'null';

    $response = [
        'name' => 'APYGG API',
        'status' => 'running',
        'version' => config('app.version', '1.0.0'),
        'endpoints' => [
            'health' => '/health',
            'health_live' => '/health/live',
            'health_ready' => '/health/ready',
            'health_detailed' => '/health/detailed',
            'search' => '/search',
            'webhooks' => '/webhooks',
            'documentation' => '/docs/api',
        ],
    ];

    if ($broadcastingEnabled) {
        $response['websockets'] = [
            'enabled' => true,
            'auth_endpoint' => '/broadcasting/auth',
            'documentation' => '/docs/websockets.md',
        ];
    }

    return response()->json($response);
});

// Health check endpoints (sin autenticación)
use App\Http\Controllers\Health\HealthController;

Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::middleware(['auth:api'])->get('/health/detailed', [HealthController::class, 'detailed']);

// ── Módulos de rutas ──────────────────────────────────────────────────────────
// Público
require __DIR__.'/api/auth.php';
require __DIR__.'/api/files.php';
require __DIR__.'/api/search.php';
require __DIR__.'/api/webhooks.php';
require __DIR__.'/api/chat.php';
require __DIR__.'/api/settings.php';

// Usuario autenticado (/user)
require __DIR__.'/api/user.php';

// Gestión de usuarios
require __DIR__.'/api/users.php';

// Admin (/admin)
require __DIR__.'/api/api-keys.php';
require __DIR__.'/api/roles.php';
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('test/sentry')->group(function () {
    Route::get('/info', [TestSentryController::class, 'info']);
    Route::post('/logs', [TestSentryController::class, 'testLogs']);
    Route::post('/exception', [TestSentryController::class, 'testException']);
});

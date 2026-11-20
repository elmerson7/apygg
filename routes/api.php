<?php

use App\Http\Controllers\TestSentryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Ruta raíz
Route::get('/', function () {
    $broadcastingEnabled = config('broadcasting.default') !== 'null';

    $response = [
        'name' => config('app.title'),
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

// ── Módulos de rutas ────────────────────────────────────────────────────────────────────
// Público
require __DIR__.'/api/auth.php';
require __DIR__.'/api/files.php';
require __DIR__.'/api/search.php';
require __DIR__.'/api/webhooks.php';
require __DIR__.'/api/mail.php';
require __DIR__.'/api/inbound-webhooks.php';
require __DIR__.'/api/llm.php';
require __DIR__.'/api/menu.php';
require __DIR__.'/api/security.php';
require __DIR__.'/api/docs.php';
require __DIR__.'/api/settings.php';
require __DIR__.'/api/tenant-onboarding.php';

// Usuario autenticado (/user)
require __DIR__.'/api/user.php';

// Idioma, zona horaria y moneda (/user/locale, /tenant/locale)
require __DIR__.'/api/i18n.php';

// Gestión de usuarios
require __DIR__.'/api/users.php';

// Admin (/admin)
require __DIR__.'/api/api-keys.php';
require __DIR__.'/api/roles.php';
require __DIR__.'/api/logs.php';
require __DIR__.'/api/feature-flags.php';
require __DIR__.'/api/plans.php';
require __DIR__.'/api/tenant-settings.php';
// ─────────────────────────────────────────────────────────────────────────────────

// Solo local/testing: son endpoints de diagnóstico que fuerzan excepciones y logs.
if (app()->environment(['local', 'testing'])) {
    Route::prefix('test/sentry')->group(function () {
        Route::get('/info', [TestSentryController::class, 'info']);
        Route::post('/logs', [TestSentryController::class, 'testLogs']);
        Route::post('/exception', [TestSentryController::class, 'testException']);
    });
}

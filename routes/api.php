<?php

use App\Http\Controllers\TestSentryController;
use App\Support\ApiPath;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Ruta raíz
Route::get('/', function () {
    $broadcastingEnabled = config('broadcasting.default') !== 'null'; // module:reverb

    $response = [
        'name' => config('app.title'),
        'status' => 'running',
        'version' => config('app.version', '1.0.0'),
        'endpoints' => [
            'health' => '/health',
            'health_live' => '/health/live',
            'health_ready' => '/health/ready',
            'health_detailed' => '/health/detailed',
            'search' => ApiPath::with('/search'), // module:search
            'webhooks' => ApiPath::with('/webhooks'),
            'documentation' => '/docs/api',
        ],
    ];

    // module:reverb:start
    if ($broadcastingEnabled) {
        $response['websockets'] = [
            'enabled' => true,
            'auth_endpoint' => ApiPath::with('/broadcasting/auth'),
            'documentation' => '/docs/websockets.md',
        ];
    }
    // module:reverb:end

    return response()->json($response);
});

// Health check endpoints (sin autenticación)
use App\Http\Controllers\Health\HealthController;

Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::middleware(['auth:api'])->get('/health/detailed', [HealthController::class, 'detailed']);

// ── Módulos de rutas ────────────────────────────────────────────────────────────────────
// Con `API_PREFIX` (p. ej. `v1`) todo lo de este grupo cuelga de `/v1`; vacío = raíz (docs/api-routes-conventions.md).
Route::prefix(ApiPath::prefix())->group(function () {
    // Público
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/files.php';
    require __DIR__.'/api/search.php'; // module:search
    require __DIR__.'/api/webhooks.php';
    require __DIR__.'/api/mail.php';
    require __DIR__.'/api/social-login.php'; // module:social-login
    require __DIR__.'/api/otp-login.php'; // module:otp-login
});

// Webhooks entrantes: fuera del prefijo (los llaman los proveedores y su URL se configura en su panel).
require __DIR__.'/api/inbound-webhooks.php';

// Métricas Prometheus: fuera del prefijo, con token o IP (docs/metrics.md).
require __DIR__.'/api/metrics.php'; // module:metrics

Route::prefix(ApiPath::prefix())->group(function () {
    require __DIR__.'/api/llm.php'; // module:llm
    require __DIR__.'/api/menu.php';
    require __DIR__.'/api/security.php';
    require __DIR__.'/api/docs.php'; // module:docs-assistant
    require __DIR__.'/api/settings.php';
    require __DIR__.'/api/tenant-onboarding.php';

    // Usuario autenticado (/user)
    require __DIR__.'/api/user.php';

    // Notificaciones del usuario: bandeja, preferencias y dispositivos push (/user/notifications, /user/devices)
    require __DIR__.'/api/notifications.php';

    // Privacidad: documentos legales, consentimientos, exportación y borrado de cuenta (/legal, /user/consents...)
    require __DIR__.'/api/privacy.php'; // module:privacy

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
    require __DIR__.'/api/audit.php';
    require __DIR__.'/api/export-import.php'; // module:export-import
    require __DIR__.'/api/impersonation.php'; // module:impersonation
    require __DIR__.'/api/maintenance.php'; // module:maintenance
    require __DIR__.'/api/billing.php'; // module:billing
    // ─────────────────────────────────────────────────────────────────────────────────
});

// Solo local/testing: son endpoints de diagnóstico que fuerzan excepciones y logs.
if (app()->environment(['local', 'testing'])) {
    Route::prefix('test/sentry')->group(function () {
        Route::get('/info', [TestSentryController::class, 'info']);
        Route::post('/logs', [TestSentryController::class, 'testLogs']);
        Route::post('/exception', [TestSentryController::class, 'testException']);
    });
}

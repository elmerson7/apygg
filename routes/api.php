<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;

Route::get('/test', fn() => response()->apiJson(['hello' => 'world']));

// Health check endpoints
Route::get('/health', [HealthController::class, 'health'])->name('health.detailed');
Route::get('/status', [HealthController::class, 'up'])->name('health.basic');

Route::get('/', function () {
    return response()->json([
        'name' => 'APYGG',
        'version' => '1.0.0',
        'status' => 'online',
        // 'documentation' => url('/docs'), // si tienes documentación
        'health_check' => url('/up'),
        'timestamp' => now()->toISOString(),
    ]);
});

require __DIR__.'/api/auth.php';
require __DIR__.'/api/users.php';
// require __DIR__.'/api/profiles.php';
// require __DIR__.'/api/messages.php';
// require __DIR__.'/api/store.php';
// require __DIR__.'/api/achievements.php';
require __DIR__.'/api/webhooks.php';
require __DIR__.'/api/admin.php';

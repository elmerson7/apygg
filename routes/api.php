<?php

use Illuminate\Support\Facades\Route;

Route::get('/test', fn() => 'hola mundo');

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
require __DIR__.'/api/profiles.php';
require __DIR__.'/api/swipes.php';
require __DIR__.'/api/matches.php';
require __DIR__.'/api/messages.php';
require __DIR__.'/api/gifts.php';
require __DIR__.'/api/store.php';
require __DIR__.'/api/achievements.php';
require __DIR__.'/api/webhooks.php';
require __DIR__.'/api/admin.php';

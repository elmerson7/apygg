<?php

/*
|--------------------------------------------------------------------------
| Versión mínima de app (middleware EnsureMinimumAppVersion, docs/sessions-and-2fa.md)
|--------------------------------------------------------------------------
|
| Con `enabled` el middleware lee `X-App-Version` y `X-App-Platform` y responde 426
| (`app_update_required`) si la versión es menor que la mínima de su plataforma.
*/

return [
    'enabled' => (bool) env('APP_MIN_VERSION_ENABLED', false),

    // true = una petición sin `X-App-Version` también recibe 426 (apps que siempre la envían).
    'require_header' => (bool) env('APP_MIN_VERSION_REQUIRE_HEADER', false),

    // Mínimas por plataforma (`ios`, `android`, `web`...) y `*` para el resto. Vacío = sin mínimo.
    'minimum' => [
        '*' => env('APP_MIN_VERSION'),
        'ios' => env('APP_MIN_VERSION_IOS'),
        'android' => env('APP_MIN_VERSION_ANDROID'),
        'web' => env('APP_MIN_VERSION_WEB'),
    ],

    'update_url' => env('APP_UPDATE_URL'),

    // Rutas (patrones de `Request::is`) que nunca se bloquean.
    'except' => [
        '/',
        'health',
        'health/*',
        'up',
        'webhooks/inbound/*',
        'docs/*',
    ],
];

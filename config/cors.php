<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | CORS_ALLOWED_ORIGINS: Lista separada por comas de orígenes permitidos
    | Ejemplo: https://app.midominio.com,https://www.midominio.com
    |
    | IMPORTANTE: Con supports_credentials=true, NUNCA uses "*" en desarrollo.
    | Los navegadores bloquean credenciales con wildcard origins.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-Request-Id',
        'X-Trace-Id', 
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];

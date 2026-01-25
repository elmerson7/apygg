<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Esta configuración controla qué orígenes, métodos y headers están
    | permitidos para las peticiones CORS. Se usa ALLOWED_ORIGINS en todos
    | los entornos (dev, staging, prod) para garantizar que funcione correctamente.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Lista de orígenes permitidos. Se obtiene de la variable de entorno
    | ALLOWED_ORIGINS que está centralizada en config/app.php.
    |
    | Formato: URLs o hosts separados por comas.
    | Ejemplo: "https://app.tudominio.com,panel.tudominio.com,localhost:8080"
    |
    | IMPORTANTE: En todos los entornos (dev, staging, prod) se debe usar
    | ALLOWED_ORIGINS con dominios específicos. NO usar "*" para garantizar
    | que la configuración funcione correctamente.
    |
    */

    'allowed_origins' => config('app.allowed_origins', []),

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Métodos HTTP permitidos en las peticiones CORS.
    |
    */

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
        'HEAD',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    |
    | Headers que el cliente puede enviar en las peticiones CORS.
    |
    */

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Trace-ID',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | Headers que el cliente puede leer en las respuestas CORS.
    |
    */

    'exposed_headers' => [
        'X-Trace-ID',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | Tiempo en segundos que el navegador puede cachear la respuesta del
    | preflight request (OPTIONS).
    |
    */

    'max_age' => env('CORS_MAX_AGE', 3600),

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Si está habilitado, permite que las peticiones incluyan credenciales
    | (cookies, headers de autorización, etc.).
    |
    | IMPORTANTE: Si supports_credentials es true, NO se puede usar "*"
    | como origen permitido.
    |
    */

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', true),

];

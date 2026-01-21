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
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | The origins that are allowed to make cross-origin requests. You may
    | specify origins as an array of strings or use '*' to allow all origins.
    |
    | WARNING: Using '*' in production is a security risk. Always specify
    | exact domains in production environments.
    |
    | Examples:
    | - ['https://example.com', 'https://www.example.com']
    | - ['*'] (only for development)
    |
    */

    'allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ? array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS'))))
        : (app()->environment(['local', 'dev', 'testing'])
            ? ['*'] // Permitir todos los orígenes en desarrollo
            : [] // En producción, debe configurarse explícitamente
        ),

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    |
    | The HTTP methods that are allowed for cross-origin requests. The default
    | methods are GET, HEAD, and POST. You may specify additional methods
    | as needed for your application.
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
    | The headers that are allowed to be sent with cross-origin requests.
    | You may specify '*' to allow all headers, but this is not recommended
    | for security reasons.
    |
    */

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Trace-ID',
        'X-API-Version',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | The headers that are exposed to the client in the response. These headers
    | can be accessed by JavaScript in the browser.
    |
    */

    'exposed_headers' => [
        'X-Trace-ID',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-API-Version',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | The number of seconds that the browser should cache the preflight
    | OPTIONS request. This reduces the number of preflight requests.
    |
    | Default: 3600 seconds (1 hour)
    |
    */

    'max_age' => (int) env('CORS_MAX_AGE', 3600),

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Indicates whether the request can include user credentials like cookies,
    | authorization headers, or TLS client certificates.
    |
    | WARNING: If set to true, you CANNOT use '*' in allowed_origins.
    | Browsers will block requests with wildcard origins when credentials
    | are enabled.
    |
    */

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', true),

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | The paths that should be handled by CORS. You may use wildcards to
    | match multiple paths. By default, all API paths are included.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

];

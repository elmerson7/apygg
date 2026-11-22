<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Adaptativo
    |--------------------------------------------------------------------------
    |
    | Configuración de límites de peticiones por tipo de endpoint.
    | Los límites se aplican por minuto y pueden ser por IP o por usuario.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Límites por Tipo de Endpoint
    |--------------------------------------------------------------------------
    |
    | Formato: 'tipo' => ['max_attempts' => int, 'decay_minutes' => int, 'by' => 'ip'|'user']
    |
    | Tipos disponibles:
    | - auth: Endpoints de autenticación (login, register, password reset)
    | - read: Endpoints de lectura (GET)
    | - write: Endpoints de escritura (POST, PUT, PATCH, DELETE)
    | - admin: Endpoints administrativos
    | - refresh: renovación del token (POST auth/refresh), por IP: sin access token válido no hay usuario
    | - sensitive: cambio de contraseña, por usuario (frena la prueba de contraseñas actuales con un token robado)
    |
    */

    'limits' => [
        'auth' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_MAX', 5),
            'decay_minutes' => 1,
            'by' => 'ip', // Por IP para prevenir fuerza bruta
        ],
        'read' => [
            'max_attempts' => (int) env('RATE_LIMIT_READ_MAX', 60),
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
        ],
        'write' => [
            'max_attempts' => (int) env('RATE_LIMIT_WRITE_MAX', 30),
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
        ],
        'admin' => [
            'max_attempts' => (int) env('RATE_LIMIT_ADMIN_MAX', 10),
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
        ],
        'refresh' => [
            // Más alto que 'auth': varias apps de una misma oficina (NAT) renuevan a la vez.
            'max_attempts' => (int) env('RATE_LIMIT_REFRESH_MAX', 20),
            'decay_minutes' => 1,
            'by' => 'ip',
        ],
        'sensitive' => [
            'max_attempts' => (int) env('RATE_LIMIT_SENSITIVE_MAX', 5),
            'decay_minutes' => 1,
            'by' => 'user',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Patrones de Rutas por Tipo
    |--------------------------------------------------------------------------
    |
    | Define qué rutas pertenecen a cada tipo de endpoint.
    | Se evalúan en orden, la primera coincidencia gana.
    |
    */

    'patterns' => [
        'auth' => [
            'auth/login',
            'auth/register',
            'auth/forgot-password',
            'auth/reset-password',
            'auth/2fa/verify',
            'auth/email/verify',
        ],
        'refresh' => [
            'auth/refresh',
        ],
        'sensitive' => [
            'auth/change-password',
        ],
        'admin' => [
            'admin/*',
        ],
        // 'read' y 'write' se detectan automáticamente por método HTTP
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers Informativos
    |--------------------------------------------------------------------------
    |
    | Si está habilitado, se agregan headers informativos en todas las respuestas:
    | - X-RateLimit-Limit: Límite máximo de peticiones
    | - X-RateLimit-Remaining: Peticiones restantes
    | - X-RateLimit-Reset: Timestamp de cuando se resetea el contador
    |
    */

    'include_headers' => true,

    /*
    |--------------------------------------------------------------------------
    | Mensaje de Error Personalizado
    |--------------------------------------------------------------------------
    |
    | Mensaje que se devuelve cuando se excede el límite de peticiones.
    |
    */

    'error_message' => 'Demasiadas peticiones. Por favor, intenta de nuevo más tarde.',

    /*
    |--------------------------------------------------------------------------
    | Excepciones
    |--------------------------------------------------------------------------
    |
    | Rutas que no deben tener rate limiting aplicado.
    |
    */

    'except' => [
        'health',
        'up',
    ],
];

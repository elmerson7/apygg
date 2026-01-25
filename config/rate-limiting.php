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
    |
    */

    'limits' => [
        'auth' => [
            'max_attempts' => 5,
            'decay_minutes' => 1,
            'by' => 'ip', // Por IP para prevenir fuerza bruta
        ],
        'read' => [
            'max_attempts' => 60,
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
        ],
        'write' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
        ],
        'admin' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
            'by' => 'user', // Por usuario autenticado
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

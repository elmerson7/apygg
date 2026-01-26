<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Keys Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración para el sistema de API Keys.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Prefijos de Keys
    |--------------------------------------------------------------------------
    |
    | Prefijos identificables para diferentes entornos.
    | Estos prefijos se agregan al inicio de cada key generada.
    |
    */

    'prefixes' => [
        'live' => 'apygg_live_',
        'test' => 'apygg_test_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Longitud de Key
    |--------------------------------------------------------------------------
    |
    | Longitud de la parte aleatoria de la key (sin incluir el prefijo).
    | Valor recomendado: 64 caracteres para seguridad óptima.
    |
    */

    'key_length' => 64,

    /*
    |--------------------------------------------------------------------------
    | Scopes Disponibles
    |--------------------------------------------------------------------------
    |
    | Lista de scopes disponibles en el sistema.
    | Formato: 'resource.action' (ej: 'users.read', 'users.write')
    | El scope '*' otorga acceso total.
    |
    | Si está vacío, se aceptan todos los scopes con formato válido.
    |
    */

    'available_scopes' => [
        // Usuarios
        'users.read',
        'users.write',
        'users.create',
        'users.delete',
        'users.restore',
        'users.manage-roles',

        // Roles
        'roles.read',
        'roles.write',
        'roles.create',
        'roles.delete',
        'roles.manage-permissions',

        // Permisos
        'permissions.read',
        'permissions.write',
        'permissions.create',
        'permissions.delete',

        // API Keys
        'api-keys.read',
        'api-keys.write',
        'api-keys.create',
        'api-keys.delete',
        'api-keys.rotate',

        // Logs
        'logs.read',
        'logs.api',
        'logs.security',
        'logs.activity',

        // Health
        'health.read',
        'health.detailed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Período de Gracia para Rotación
    |--------------------------------------------------------------------------
    |
    | Número de días que la key antigua permanece activa después de rotar.
    | Durante este período, tanto la key antigua como la nueva funcionan.
    |
    */

    'rotation_grace_period_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Tiempo de vida del cache para validaciones de API Keys (en segundos).
    | Valor recomendado: 300 segundos (5 minutos).
    |
    */

    'cache_ttl' => 300,

];

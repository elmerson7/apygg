<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Planes y límites por tenant (docs/settings-and-flags.md §Límites)
    |--------------------------------------------------------------------------
    |
    | Opt-in: con `PLAN_LIMITS_ENABLED=false` (por defecto) nada se limita.
    | Los límites solo existen con multiempresa activo y un tenant en el contexto.
    | Esto NO es facturación (fase de suscripciones): solo topes de uso.
    |
    | `limits` declara las claves de límite; en cada plan, `null` = ilimitado.
    | El plan de un tenant sale de `tenants.plan` (o `default`); cada límite se
    | puede sobrescribir por tenant en BD (tabla `tenant_limit_overrides`).
    |
    */
    'enabled' => (bool) env('PLAN_LIMITS_ENABLED', false),

    'default' => env('PLAN_DEFAULT', 'free'),

    'cache_ttl' => (int) env('PLAN_LIMITS_CACHE_TTL', 60),

    'limits' => [
        'max_members' => ['description' => 'Miembros activos o invitados del tenant'],
        'max_storage_mb' => ['description' => 'Almacenamiento de archivos del tenant (MB)'],
        'max_file_mb' => ['description' => 'Tamaño máximo de un archivo del tenant (MB)'],
        'max_api_keys' => ['description' => 'API keys vigentes del tenant'],
    ],

    'plans' => [
        'free' => [
            'name' => 'Free',
            'limits' => ['max_members' => 3, 'max_storage_mb' => 100, 'max_file_mb' => 10, 'max_api_keys' => 2],
        ],
        'pro' => [
            'name' => 'Pro',
            'limits' => ['max_members' => 25, 'max_storage_mb' => 5120, 'max_file_mb' => 100, 'max_api_keys' => 20],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'limits' => ['max_members' => null, 'max_storage_mb' => null, 'max_file_mb' => null, 'max_api_keys' => null],
        ],
    ],
];

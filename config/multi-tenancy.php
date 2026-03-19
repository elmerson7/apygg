<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Habilitar o deshabilitar el soporte multi-tenant.
    | Cuando está deshabilitado, el middleware ResolveTenant no filtra
    | por tenant y los modelos no aplican el TenantScope.
    |
    */
    'enabled' => env('MULTI_TENANCY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Tenant por Defecto
    |--------------------------------------------------------------------------
    |
    | ID del tenant por defecto. Se usa cuando no se puede resolver
    | el tenant desde header, subdomain o JWT.
    | Útil para modo single-tenant con código multi-tenant.
    |
    */
    'default_tenant_id' => env('DEFAULT_TENANT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Estrategia de Resolución
    |--------------------------------------------------------------------------
    |
    | Define cómo se resuelve el tenant actual:
    | - header: Desde X-Tenant-ID header
    | - subdomain: Desde subdomain (ej: acme.tuapp.com)
    | - jwt: Desde claim tenant_id en el token JWT
    | - auto: Intenta todas las estrategias en orden
    |
    */
    'resolution_strategy' => env('TENANT_RESOLUTION_STRATEGY', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Columna Tenant ID
    |--------------------------------------------------------------------------
    |
    | Nombre de la columna que almacena el tenant_id en las tablas.
    |
    */
    'column' => 'tenant_id',

    /*
    |--------------------------------------------------------------------------
    | Modelos con Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Lista de modelos que usan el trait BelongsToTenant.
    | Solo estos modelos aplicarán el TenantScope.
    |
    */
    'models' => [
        // Agregar modelos aquí cuando se active multi-tenancy
        // App\Models\User::class,
        // App\Models\ApiKey::class,
        // App\Models\File::class,
    ],
];

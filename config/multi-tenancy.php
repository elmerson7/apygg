<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy (opt-in)
    |--------------------------------------------------------------------------
    |
    | Apagado por defecto: un proyecto de una sola empresa no paga nada (ni el scope,
    | ni los hooks, ni el middleware `tenant`). Con true, los modelos con `BelongsToTenant`
    | quedan aislados fail-closed. Ver docs/multi-tenancy.md.
    |
    */
    'enabled' => (bool) env('MULTI_TENANCY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Tenant por defecto
    |--------------------------------------------------------------------------
    |
    | Solo lo usa la migración de equipos de Spatie (docs/permissions.md) para atribuir
    | las asignaciones existentes. NO se usa para resolver peticiones.
    |
    */
    'default_tenant_id' => env('DEFAULT_TENANT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Resolución del tenant (middleware `tenant`)
    |--------------------------------------------------------------------------
    |
    | Estrategias en orden; gana la primera que aporte un identificador:
    | - header:    cabecera `header` (id o slug)
    | - route:     parámetro de ruta `route_parameter` (id o slug)
    | - subdomain: `<slug>.<base_domain>`
    | - domain:    dominio propio completo (`tenants.domain`)
    |
    */
    'resolution' => [
        'strategies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_RESOLUTION', 'header,route'))))),
        'header' => env('TENANT_HEADER', 'X-Tenant'),
        'route_parameter' => env('TENANT_ROUTE_PARAMETER', 'tenant'),
        'base_domain' => env('TENANT_BASE_DOMAIN'),
    ],
];

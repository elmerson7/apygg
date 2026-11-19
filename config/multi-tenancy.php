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

    /*
    |--------------------------------------------------------------------------
    | Alta de empresas, invitaciones y miembros (docs/tenant-onboarding.md)
    |--------------------------------------------------------------------------
    |
    | Solo existen con `enabled=true`: con multiempresa apagado sus rutas responden 404.
    |
    | - roles: roles DEL TENANT (`tenant_user.role`). `rank` ordena la jerarquía (nadie asigna ni toca
    |   un rol por encima del suyo); `spatie_role` es el rol de Spatie que se refleja al asignarlo
    |   (con PERMISSION_TEAMS=true, en el equipo del tenant). RoleSeeder los crea con sus permisos.
    | - signup: alta de empresa por un usuario autenticado. `enabled=false` = solo la plataforma crea tenants.
    |   `require_approval=true` deja el tenant en `pending` hasta `tenancy:approve`.
    | - invitations: caducidad, tope de pendientes por tenant y plantilla del enlace del front
    |   (`{token}` se sustituye por el token en claro, que solo viaja en el correo).
    |
    */
    'onboarding' => [
        'roles' => [
            'owner' => ['rank' => 30, 'spatie_role' => 'tenant-owner'],
            'admin' => ['rank' => 20, 'spatie_role' => 'tenant-admin'],
            'member' => ['rank' => 10, 'spatie_role' => 'tenant-member'],
        ],
        'default_role' => 'member',

        'signup' => [
            'enabled' => (bool) env('TENANT_SIGNUP_ENABLED', false),
            'require_approval' => (bool) env('TENANT_SIGNUP_REQUIRE_APPROVAL', false),
            'trial_days' => env('TENANT_TRIAL_DAYS') !== null && env('TENANT_TRIAL_DAYS') !== '' ? (int) env('TENANT_TRIAL_DAYS') : null,
            'max_tenants_per_user' => (int) env('TENANT_SIGNUP_MAX_PER_USER', 10),
            'reserved_slugs' => ['www', 'api', 'app', 'admin', 'backoffice', 'mail', 'static', 'assets', 'support', 'status', 'docs', 'health', 'platform'],
        ],

        'invitations' => [
            'ttl_hours' => (int) env('TENANT_INVITATION_TTL_HOURS', 168),
            'max_pending' => (int) env('TENANT_INVITATION_MAX_PENDING', 50),
            'accept_url' => env('TENANT_INVITATION_URL', rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/invitations/accept?token={token}'),
            // false: el email del usuario que acepta debe ser el invitado (comparación en minúsculas).
            'allow_email_mismatch' => (bool) env('TENANT_INVITATION_ALLOW_EMAIL_MISMATCH', false),
        ],
    ],
];

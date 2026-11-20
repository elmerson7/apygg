<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ajustes tipados por niveles (docs/settings-and-flags.md)
    |--------------------------------------------------------------------------
    |
    | Registro de ajustes DECLARADOS. Resolución: valor del tenant -> valor de
    | plataforma (tabla `settings`) -> `default` de aquí. Para añadir un ajuste
    | basta una entrada nueva en `definitions`; no hay que tocar código.
    |
    | Campos de cada definición:
    | - type:        bool | int | string | enum | json            (obligatorio)
    | - default:     valor por defecto (debe cumplir el tipo)
    | - values:      lista de valores permitidos (solo `enum`)
    | - min / max:   rango (`int`) o longitud (`string`)
    | - rules:       reglas Laravel extra sobre el valor (p. ej. ['email'])
    | - platform:    ¿lo edita la plataforma? (por defecto true)
    | - tenant:      ¿lo puede sobrescribir cada tenant? (por defecto false)
    | - group / description: solo informativos
    |
    | Un tenant nunca puede escribir un ajuste con `tenant => false`, y la
    | plataforma tampoco uno con `platform => false` (queda fijo en `default`).
    |
    */

    // TTL (segundos) de la caché corta de ajustes (plataforma y por tenant).
    'cache_ttl' => (int) env('SETTINGS_CACHE_TTL', 60),

    'definitions' => [
        'general.support_email' => [
            'type' => 'string',
            'default' => null,
            'rules' => ['email', 'max:255'],
            'tenant' => true,
            'group' => 'general',
            'description' => 'Correo de soporte que se muestra a los usuarios',
        ],
        'general.theme' => [
            'type' => 'enum',
            'values' => ['light', 'dark', 'auto'],
            'default' => 'auto',
            'tenant' => true,
            'group' => 'general',
            'description' => 'Tema visual por defecto',
        ],
        'security.allow_public_sharing' => [
            'type' => 'bool',
            'default' => false,
            'tenant' => true,
            'group' => 'security',
            'description' => 'Permite enlaces públicos de archivos',
        ],
        'brand.name' => [
            'type' => 'string',
            'default' => null,
            'max' => 120,
            'tenant' => true,
            'group' => 'brand',
            'description' => 'Nombre de marca en correos (por defecto, config/brand.php)',
        ],
        'brand.logo_url' => [
            'type' => 'string',
            'default' => null,
            'max' => 500,
            'rules' => ['url:https'],
            'tenant' => true,
            'group' => 'brand',
            'description' => 'URL (https) del logo que se muestra en la cabecera de los correos',
        ],
        'brand.primary_color' => [
            'type' => 'string',
            'default' => null,
            'max' => 7,
            'rules' => ['regex:/^#[0-9a-fA-F]{6}$/'],
            'tenant' => true,
            'group' => 'brand',
            'description' => 'Color primario (#rrggbb) de los botones de los correos',
        ],
        'brand.legal_address' => [
            'type' => 'string',
            'default' => null,
            'max' => 300,
            'tenant' => true,
            'group' => 'brand',
            'description' => 'Dirección legal que aparece en el pie de los correos',
        ],
        'brand.support_email' => [
            'type' => 'string',
            'default' => null,
            'rules' => ['email', 'max:255'],
            'tenant' => true,
            'group' => 'brand',
            'description' => 'Correo de soporte que aparece en el pie de los correos',
        ],
        'brand.social' => [
            'type' => 'json',
            'default' => [],
            'tenant' => true,
            'group' => 'brand',
            'description' => 'Redes sociales del pie de los correos: {"twitter": "https://..."}',
        ],
        'ui.branding' => [
            'type' => 'json',
            'default' => [],
            'tenant' => true,
            'group' => 'ui',
            'description' => 'Marca visual (colores, logo, etc.) en formato libre',
        ],
        'system.maintenance_message' => [
            'type' => 'string',
            'default' => null,
            'max' => 500,
            'tenant' => false,
            'group' => 'system',
            'description' => 'Aviso global de plataforma (los tenants no lo cambian)',
        ],
    ],
];

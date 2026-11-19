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
        'security.session_timeout_minutes' => [
            'type' => 'int',
            'default' => 60,
            'min' => 5,
            'max' => 1440,
            'tenant' => true,
            'group' => 'security',
            'description' => 'Inactividad máxima antes de pedir sesión de nuevo',
        ],
        'security.allow_public_sharing' => [
            'type' => 'bool',
            'default' => false,
            'tenant' => true,
            'group' => 'security',
            'description' => 'Permite enlaces públicos de archivos',
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

<?php

/*
|--------------------------------------------------------------------------
| Marca para correos y documentos
|--------------------------------------------------------------------------
|
| Tokens de marca de la PLATAFORMA que usan las plantillas de correo (layout de Markdown mail).
| Un tenant puede sobrescribir los que declara `config/settings.php` con `tenant => true`
| (`brand.name`, `brand.logo_url`, `brand.primary_color`, `brand.legal_address`, `brand.support_email`,
| `brand.social`); lo que no sobrescribe sale de aquí. Ver docs/mail-system.md §Marca.
|
*/

return [
    'name' => env('BRAND_NAME', env('APP_NAME', 'APYGG')),
    'logo_url' => env('BRAND_LOGO_URL'),
    'url' => env('BRAND_URL'),   // por defecto APP_URL

    'colors' => [
        'primary' => env('BRAND_COLOR_PRIMARY', '#2d3748'),   // botones
        'link' => env('BRAND_COLOR_LINK', '#3869d4'),
        'text' => env('BRAND_COLOR_TEXT', '#718096'),
        'background' => env('BRAND_COLOR_BACKGROUND', '#edf2f7'),
    ],

    'legal_address' => env('BRAND_LEGAL_ADDRESS'),
    'support_email' => env('BRAND_SUPPORT_EMAIL'),

    // red => URL (se muestran en el pie, en este orden)
    'social' => [
        // 'twitter' => 'https://twitter.com/tu_marca',
    ],
];

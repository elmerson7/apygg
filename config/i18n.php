<?php

/*
|--------------------------------------------------------------------------
| Internacionalización (idioma, zona horaria y moneda)
|--------------------------------------------------------------------------
|
| Ver docs/i18n.md. Precedencia (LocaleResolver): usuario -> tenant (multiempresa)
| -> cabecera Accept-Language (solo `locales`) -> estos valores por defecto.
| La BD y la API siguen en UTC: `timezone` es solo de presentación.
|
*/

return [
    // Idiomas con traducciones en lang/<código>/. Cada uno exige sus archivos (paridad de claves).
    'locales' => array_values(array_filter(array_map('trim', explode(',', (string) env('I18N_LOCALES', 'es,en'))))),

    // Idioma por defecto de la instalación: el de `app.locale` (APP_LOCALE).
    'default_locale' => env('APP_LOCALE', 'es'),

    // Zona horaria por defecto para presentación, correos y cálculos de "día".
    'default_timezone' => env('I18N_DEFAULT_TIMEZONE', 'UTC'),

    // Moneda por defecto (código ISO 4217).
    'default_currency' => env('I18N_DEFAULT_CURRENCY', 'USD'),

    // Monedas aceptables: opcionalmente se restringen con I18N_CURRENCIES (lista separada por comas).
    // Vacío = todas las de config/currencies.php (las que `Money` sabe manejar). Una moneda que no esté en
    // config/currencies.php nunca se acepta, aunque figure aquí.
    'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('I18N_CURRENCIES', ''))))),
];

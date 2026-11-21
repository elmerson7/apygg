<?php

/*
|--------------------------------------------------------------------------
| Prefijo de versión de la API (opcional)
|--------------------------------------------------------------------------
|
| `API_PREFIX` vacío (por defecto): todas las rutas de la API cuelgan de la raíz, como siempre.
| Con `API_PREFIX=v1` las rutas de la aplicación pasan a `/v1/...`. Pensado para proyectos que
| alimentan apps móviles (no se pueden forzar a actualizar). Detalle: docs/api-routes-conventions.md.
|
| Se admite `v1`, `/v1` o `v1/` (se normaliza a `v1`). Léelo con `App\Support\ApiPath::prefix()`.
*/
return [
    'prefix' => env('API_PREFIX', ''),

    /*
    | Rutas que NUNCA llevan prefijo (las llaman servidores, balanceadores o personas, no las apps, y su URL se
    | configura fuera): salud, webhooks entrantes y de pasarelas, documentación OpenAPI, back-office y paneles web.
    | Patrones de `Request::is` (sin `/` inicial). `broadcasting/auth` SÍ lleva prefijo: lo llama el cliente de la app.
    */
    'unprefixed' => [
        '/',
        'up',
        'health',
        'health/*',
        'webhooks/inbound/*',
        'webhooks/mail',
        'docs/api',
        'docs/api.json',
        'backoffice',
        'backoffice/*',
        'livewire*',
        'horizon',
        'horizon/*',
        'telescope',
        'telescope/*',
        'test/sentry/*', // solo local/testing
    ],
];

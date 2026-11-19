<?php

/*
|--------------------------------------------------------------------------
| Back-office interno (Filament) — módulo OPCIONAL
|--------------------------------------------------------------------------
|
| Panel web para el dueño de la plataforma (no es el panel de los clientes de
| cada SaaS: ese sigue siendo API + frontend propio). Todo el módulo vive en
| `app/Filament/`, `app/Providers/Filament/`, este archivo y sus vistas. Para
| quitarlo, ver docs/backoffice.md.
|
| El acceso exige sesión web + el permiso `backoffice.access` + MFA (TOTP).
|
*/

return [

    /*
    | Si es false el panel no se registra: la ruta no existe (404) y no cuesta nada.
    */
    'enabled' => (bool) env('BACKOFFICE_ENABLED', true),

    /*
    | Ruta del panel. No uses `admin`: ese prefijo es de la API (`/admin/logs`, ...).
    */
    'path' => trim((string) env('BACKOFFICE_PATH', 'backoffice'), '/'),

    /*
    | Nombre que se muestra en la cabecera del panel.
    */
    'brand' => env('BACKOFFICE_BRAND', env('APP_NAME', 'APYGG').' · Back-office'),

    /*
    | Permiso que da acceso al panel (guard `api`, como el resto de permisos).
    | Los permisos de cada recurso son los mismos que usa la API.
    */
    'permission' => 'backoffice.access',

];

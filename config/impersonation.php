<?php

return [

    /*
    | Impersonación de soporte (docs/impersonation.md). Apagada = POST /admin/impersonate responde 404 de dominio
    | (`impersonation_disabled`); los tokens ya emitidos siguen caducando solos.
    */
    'enabled' => env('IMPERSONATION_ENABLED', true),

    /*
    | Vida del token de impersonación, en minutos. No hay refresh token: al caducar hay que impersonar de nuevo.
    */
    'ttl_minutes' => (int) env('IMPERSONATION_TTL', 15),

    /*
    | Rutas que NUNCA se pueden usar impersonando (403 `impersonation_forbidden`). Formato `METODO ruta`, con `*` de
    | comodín en ambos (`fnmatch`; la ruta sin `/` inicial). Cambiar contraseña o correo, 2FA, borrar cuenta, API keys
    | y volver a impersonar. Añade aquí las operaciones sensibles de tu proyecto.
    */
    'blocked_routes' => [
        '* auth/change-password',
        '* auth/forgot-password',
        '* auth/reset-password',
        'PUT user/profile',
        'PATCH user/profile',
        '* user/2fa*',
        '* auth/2fa*',
        '* auth/email/*',
        'DELETE user/sessions*',
        '* user/email*',
        '* user/delete*',
        'DELETE user',
        'PUT users/*',
        'PATCH users/*',
        'DELETE users/*',
        'POST users/*',
        '* api-keys*',
        'POST admin/impersonate/[0-9]*', // stop sí se permite
        '* admin/maintenance*',
        // Privilegios y accesos que sobrevivirían a la impersonación (soporte se invita, sube un rol o
        // deja un webhook o una suscripción a su nombre). Se puede leer, no cambiar.
        'POST roles*', 'PUT roles/*', 'DELETE roles/*', 'POST permissions*', 'PUT permissions/*', 'DELETE permissions/*',
        'POST tenants', 'POST tenant/invitations*', 'DELETE tenant/invitations/*',
        'POST tenant/members/*', 'PATCH tenant/members/*', 'DELETE tenant/members/*',
        'POST webhooks*', 'PUT webhooks/*', 'DELETE webhooks/*',
        'POST tenant/billing/*', 'PUT tenant/billing/*', 'PATCH tenant/billing/*', 'DELETE tenant/billing/*', // module:billing
    ],
];

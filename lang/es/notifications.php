<?php

/*
| Textos de los tipos de notificación declarados en config/notifications.php (docs/notifications.md).
| `types.<clave>.title|body`; `:param` se sustituye con los parámetros de `notify()`.
*/

return [
    'greeting' => '¡Hola :name!',
    'action' => 'Ver más',

    'types' => [
        'security_alert' => [
            'title' => 'Alerta de seguridad',
            'body' => ':message',
        ],
        'account_activity' => [
            'title' => 'Actividad en tu cuenta',
            'body' => ':message',
        ],
        'system_announcement' => [
            'title' => ':title',
            'body' => ':message',
        ],
        'export_ready' => [
            'title' => 'Exportación lista',
            'body' => 'Tu exportación está lista para descargar.',
        ],
        'import_finished' => [
            'title' => 'Importación terminada',
            'body' => 'Tu importación terminó: :message',
        ],
    ],
];

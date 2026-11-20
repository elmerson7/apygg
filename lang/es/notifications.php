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
        'data_export_ready' => [
            'title' => 'Tu exportación de datos está lista',
            'body' => 'Puedes descargarla durante las próximas :hours horas.',
        ],
        'account_deletion_scheduled' => [
            'title' => 'Borrado de cuenta programado',
            'body' => 'Tu cuenta se eliminará el :date. Si no fuiste tú, cancela la solicitud o cambia tu contraseña.',
        ],
        'account_deletion_blocked' => [
            'title' => 'No se pudo borrar tu cuenta',
            'body' => ':reason',
        ],
    ],
];

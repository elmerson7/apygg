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
        'billing_trial_ending' => [
            'title' => 'Tu prueba termina pronto',
            'body' => 'La prueba del plan :plan termina en :days día(s), el :date. Después se cobrará la suscripción.',
        ],
        'billing_renewal_upcoming' => [
            'title' => 'Tu suscripción se renueva pronto',
            'body' => 'El plan :plan se renovará el :date por :amount.',
        ],
        'billing_invoice_paid' => [
            'title' => 'Factura pagada',
            'body' => 'Recibimos el pago de la factura :number por :amount.',
        ],
        'billing_payment_failed' => [
            'title' => 'No pudimos cobrar tu suscripción',
            'body' => 'El cobro de la factura :number (:amount) fue rechazado. Lo reintentaremos el :retry_date; puedes pagarla antes.',
        ],
        'billing_final_warning' => [
            'title' => 'Último aviso antes de cancelar tu suscripción',
            'body' => 'El cobro de la factura :number (:amount) sigue sin completarse. El último reintento será el :retry_date; si falla, tu suscripción terminará.',
        ],
        'billing_subscription_canceled' => [
            'title' => 'Suscripción cancelada',
            'body' => 'Tu suscripción al plan :plan fue cancelada.',
        ],
        'billing_subscription_expired' => [
            'title' => 'Suscripción vencida',
            'body' => 'Tu suscripción al plan :plan venció por falta de pago.',
        ],
    ],
];

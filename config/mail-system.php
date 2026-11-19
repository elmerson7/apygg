<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sistema de Correo Multi-cuenta
    |--------------------------------------------------------------------------
    |
    | Configuración del sistema de correo propio del boilerplate (tablas
    | mail_providers, mail_accounts, mail_template_configs, mail_logs).
    |
    | No confundir con config/mail.php, que es la configuración del mailer de
    | Laravel: este archivo controla la capa de arriba (cuentas, plantillas,
    | registro e historial).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Retención del Historial
    |--------------------------------------------------------------------------
    |
    | Días que se conservan los registros de mail_logs. La tabla crece con cada
    | correo enviado; el comando `mail:logs-clean` aplica esta política.
    |
    */

    'logs_retention_days' => env('MAIL_LOGS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Webhooks del Proveedor
    |--------------------------------------------------------------------------
    |
    | Endpoint que recibe los eventos de entrega (POST /webhooks/mail).
    | El secreto se configura en services.resend.webhook_secret; sin él, el
    | endpoint rechaza todas las peticiones.
    |
    */

    'webhooks' => [
        'enabled' => env('MAIL_WEBHOOKS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cola
    |--------------------------------------------------------------------------
    |
    | Cola en la que van los Mailables/Notifications del sistema (SimpleMail,
    | Welcome/ResetPassword). Separar el correo de la cola por
    | defecto evita que un pico de trabajos pesados retrase las notificaciones.
    |
    */

    'queue' => env('MAIL_QUEUE', 'default'),

];

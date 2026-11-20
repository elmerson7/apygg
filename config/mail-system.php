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

    /*
    |--------------------------------------------------------------------------
    | Lista de supresión
    |--------------------------------------------------------------------------
    |
    | Direcciones a las que no se envía: rebote permanente o queja (llegan por el webhook de Resend) o
    | puestas a mano (`/admin/mail/suppressions`). Se aplica al enviar, no al encolar.
    |
    | - critical_templates: claves de plantilla (HasMailTemplate) de correos críticos.
    | - critical_ignore_reasons: motivos de supresión que esos correos ignoran. Por defecto un reset de
    |   contraseña sale aunque la dirección rebotara (puede haberse arreglado), pero NO si se quejó.
    |
    */

    'suppression' => [
        'enabled' => env('MAIL_SUPPRESSION_ENABLED', true),
        'critical_templates' => ['password_reset'],
        'critical_ignore_reasons' => ['bounce'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dominios reservados
    |--------------------------------------------------------------------------
    |
    | Nunca se envía a ellos (RFC 2606/6761): se registran como `skipped`. Evita rebotes que dañan la
    | reputación del remitente con datos de prueba. Las entradas son dominios (incluye subdominios) o
    | TLDs (`.test`). Desactívalo en entornos de prueba que envíen a `@example.com` (phpunit.xml lo hace).
    |
    */

    'block_reserved_domains' => env('MAIL_BLOCK_RESERVED_DOMAINS', true),

    'reserved_domains' => [
        'example.com', 'example.org', 'example.net',
        '.test', '.invalid', '.localhost', '.example',
    ],

];

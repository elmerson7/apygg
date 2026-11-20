<?php

/*
|--------------------------------------------------------------------------
| Notificaciones multicanal (docs/notifications.md)
|--------------------------------------------------------------------------
|
| Un tipo de notificación se DECLARA aquí y se envía con
| `NotificationServiceInterface::notify($users, 'clave', $params)`. Su texto vive en
| `lang/<idioma>/notifications.php` bajo `types.<clave>` (`title`, `body`; `:param` se
| sustituye con `$params`). La clave usa snake_case, sin puntos.
|
*/

return [

    /** Cola de las notificaciones (un job por destinatario; reintenta solo lo que falló). */
    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    /** Reintentos del job y espera entre ellos (segundos). */
    'tries' => (int) env('NOTIFICATIONS_TRIES', 3),
    'backoff' => [10, 60],

    /**
     * Canales que el sistema sabe usar. `database` es la bandeja in-app; `sms` y `push` usan las
     * integraciones real/simulada (`SMS_MODE`, `PUSH_MODE`; docs/integrations.md).
     */
    'channels' => ['mail', 'database', 'push', 'sms'],

    /**
     * Tipos de notificación.
     *   channels   canales por defecto (subconjunto de `channels`)
     *   mandatory  true = el usuario no puede desactivarla y NO respeta las horas de silencio (seguridad)
     *
     * @var array<string, array{channels: list<string>, mandatory?: bool}>
     */
    'types' => [
        'security_alert' => ['channels' => ['mail', 'database', 'push'], 'mandatory' => true],
        'account_activity' => ['channels' => ['database', 'push']],
        'system_announcement' => ['channels' => ['database', 'mail']],
    ],

    /**
     * Horas de silencio (por usuario, en su zona horaria): los canales listados NO se envían dentro
     * de la franja salvo en tipos `mandatory`. La bandeja y el correo siguen llegando.
     */
    'quiet_hours' => [
        'channels' => ['push', 'sms'],
    ],

    /** Entregas: reclamo idempotente, barrido de atascadas y retención. */
    'deliveries' => [
        // Una entrega «enviando» más tiempo que esto puede reclamarla otro intento (> timeout del job).
        'lease_seconds' => (int) env('NOTIFICATIONS_LEASE_SECONDS', 120),
        // `notifications:sweep` marca como fallidas las «enviando» de más de N minutos.
        'stuck_minutes' => (int) env('NOTIFICATIONS_STUCK_MINUTES', 15),
        // `notifications:purge` borra entregas terminadas más antiguas que N días.
        'retention_days' => (int) env('NOTIFICATIONS_DELIVERY_RETENTION_DAYS', 90),
    ],

    /** Bandeja in-app. `retention_days`: se borran las LEÍDAS más antiguas (0 = no purgar). */
    'inbox' => [
        'per_page' => 20,
        'max_per_page' => 100,
        'retention_days' => (int) env('NOTIFICATIONS_INBOX_RETENTION_DAYS', 180),
    ],

    /** Dispositivos push. */
    'devices' => [
        'max_per_user' => (int) env('NOTIFICATIONS_MAX_DEVICES', 20),
        // `notifications:purge` borra dispositivos sin verse en N días (0 = no purgar).
        'stale_days' => (int) env('NOTIFICATIONS_DEVICE_STALE_DAYS', 180),
    ],
];

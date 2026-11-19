<?php

use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Events\UserRestored;
use App\Events\UserUpdated;

return [

    /*
    |--------------------------------------------------------------------------
    | Webhooks Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración de webhooks del sistema.
    | Define los eventos disponibles y su mapeo con eventos de Laravel.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Eventos Disponibles
    |--------------------------------------------------------------------------
    |
    | Lista de eventos que pueden ser suscritos por webhooks.
    | Formato: 'nombre.evento' => 'Clase\Del\Evento\Laravel'
    |
    */
    'events' => [
        // Eventos de Usuario
        'user.created' => UserCreated::class,
        'user.updated' => UserUpdated::class,
        'user.deleted' => UserDeleted::class,
        'user.restored' => UserRestored::class,
        'user.logged_in' => UserLoggedIn::class,
        'user.logged_out' => UserLoggedOut::class,

        // Eventos de Autorización
        'role.assigned' => RoleAssigned::class,
        'role.removed' => RoleRemoved::class,
        'permission.granted' => PermissionGranted::class,
        'permission.revoked' => PermissionRevoked::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración por Defecto
    |--------------------------------------------------------------------------
    |
    | Valores por defecto para nuevos webhooks.
    |
    */
    'defaults' => [
        'timeout' => 30, // Segundos (timeout HTTP por webhook)
        'max_retries' => 3,
        'status' => 'active',
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Reintentos
    |--------------------------------------------------------------------------
    |
    | Configuración para reintentos automáticos de webhooks fallidos.
    |
    */
    'retry' => [
        'backoff_multiplier' => 2, // Multiplicador para backoff exponencial
        'max_delay' => 3600, // Máximo delay en segundos (1 hora)
        'initial_delay' => 60, // Delay inicial en segundos (1 minuto)
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Seguridad
    |--------------------------------------------------------------------------
    |
    | Configuración relacionada con la seguridad de webhooks.
    |
    */
    'security' => [
        'signature_header' => 'X-Webhook-Signature',
        'timestamp_header' => 'X-Webhook-Timestamp',
        'timestamp_tolerance' => 300, // Tolerancia en segundos (5 minutos)
        'algorithm' => 'sha256', // Algoritmo para firma HMAC
        'rotation_grace_days' => 7, // Días que el secreto anterior sigue firmando tras rotar
    ],

    /*
    |--------------------------------------------------------------------------
    | Protección SSRF (URLs de destino)
    |--------------------------------------------------------------------------
    |
    | Se aplica al crear/actualizar el webhook y de nuevo en cada envío. Bloquea IPs
    | privadas, loopback, link-local (metadata cloud), CGNAT e IPv6 locales, y no sigue
    | redirecciones. `allow_http`: permitir http (solo desarrollo). `allowed_hosts`:
    | hostnames internos de confianza que se saltan el bloqueo de IPs (p. ej.
    | `host.docker.internal` en local); déjalo vacío en producción.
    |
    */
    'ssrf' => [
        'allow_http' => (bool) env('WEBHOOKS_ALLOW_HTTP', false),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBHOOKS_ALLOWED_HOSTS', ''))))),
    ],

    /*
    | Timeout HTTP máximo que admite un webhook (la validación lo exige y el envío lo
    | recorta). `job_timeout` es el timeout del SendWebhookJob: debe ser mayor que
    | `max_timeout` (resolución + conexión + margen) y menor que `retry_after` de la
    | conexión de cola; un test lo comprueba.
    */
    'max_timeout' => (int) env('WEBHOOK_MAX_TIMEOUT', 120),
    'job_timeout' => (int) env('WEBHOOK_JOB_TIMEOUT', 150),

    /*
    | Máximo de caracteres de la respuesta del destino que se guardan en cada entrega.
    */
    'max_response_body' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Cola de Webhooks
    |--------------------------------------------------------------------------
    |
    | Configuración de la cola para procesamiento de webhooks.
    |
    */
    'queue' => [
        'connection' => env('WEBHOOK_QUEUE_CONNECTION', 'redis'),
        'queue' => env('WEBHOOK_QUEUE_NAME', 'webhooks'),
    ],

];

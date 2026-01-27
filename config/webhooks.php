<?php

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
        'user.created' => \App\Events\UserCreated::class,
        'user.updated' => \App\Events\UserUpdated::class,
        'user.deleted' => \App\Events\UserDeleted::class,
        'user.restored' => \App\Events\UserRestored::class,
        'user.logged_in' => \App\Events\UserLoggedIn::class,
        'user.logged_out' => \App\Events\UserLoggedOut::class,

        // Eventos de Autorización
        'role.assigned' => \App\Events\RoleAssigned::class,
        'role.removed' => \App\Events\RoleRemoved::class,
        'permission.granted' => \App\Events\PermissionGranted::class,
        'permission.revoked' => \App\Events\PermissionRevoked::class,
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
        'timeout' => 30, // Segundos
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
    ],

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

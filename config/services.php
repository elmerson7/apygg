<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        // Secreto de los webhooks de entrega (formato whsec_...). Sin él,
        // POST /webhooks/mail rechaza todas las peticiones.
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    // Stripe (docs/billing.md §Stripe). PAYMENTS_MODE=real + PAYMENTS_PROVIDER=stripe. Nunca claves reales en el repo.
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        // Secreto(s) del endpoint de webhooks (whsec_...). Varios separados por comas durante una rotación.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        // Fija la versión de la API para que un cambio de Stripe no altere el comportamiento (p. ej. 2025-09-30.clover).
        'api_version' => env('STRIPE_API_VERSION'),
        // Prefijo de TODAS las claves de idempotencia que se envían a Stripe (`Idempotency-Key`). Staging y producción con la
        // misma cuenta de Stripe no deben compartir claves (`inv-7-a1` existe en las dos instalaciones y Stripe devolvería el
        // cobro de la otra). Por defecto: APP_ENV + 8 hex de un hash de APP_KEY (id de instalación). Fíjalo EXPLÍCITO en
        // producción (PAYMENTS_KEY_PREFIX=acme-prod): cambiarlo con cobros en vuelo reenvía con otra clave (la consulta por
        // clave previa al reenvío evita el doble cobro, pero no lo cambies sin necesidad). Vacío = sin prefijo.
        'key_prefix' => env('PAYMENTS_KEY_PREFIX') ?? (string) env('APP_ENV', 'production').'-'.substr(hash('sha256', (string) env('APP_KEY', 'apygg')), 0, 8),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

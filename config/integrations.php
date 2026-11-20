<?php

use App\Integrations\Webhooks\Handlers\ResendMailEventsHandler;

/*
|--------------------------------------------------------------------------
| Integraciones externas (docs/integrations.md)
|--------------------------------------------------------------------------
|
| Cada servicio de terceros tiene un `mode`: `fake` (versión simulada determinista, sin red;
| por defecto, para que nada llame a un proveedor por accidente) o `real`. Un único punto
| (`IntegrationsServiceProvider`) elige la implementación a partir de `<SERVICIO>_MODE`.
|
*/

return [

    /*
    | Valores por defecto de TODO cliente HTTP de integración. Sin timeout, un proveedor lento
    | bloquea un worker. Debe ser menor que el `timeout` del job que lo llama.
    */
    'defaults' => [
        'timeout' => (int) env('INTEGRATIONS_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('INTEGRATIONS_HTTP_CONNECT_TIMEOUT', 3),
        'retry' => [
            'max_attempts' => (int) env('INTEGRATIONS_RETRY_ATTEMPTS', 3),
            'base_delay_ms' => 200,
            'max_delay_ms' => 5000,
            'max_retry_after_ms' => 30000,
        ],
    ],

    /*
    | SMS: ejemplo completo de integración (SmsSenderInterface).
    |   SMS_MODE=fake            FakeSmsSender (registra las llamadas, sin red)
    |   SMS_MODE=real SMS_DRIVER=log    LogSmsSender (solo deja una línea en el log)
    |   SMS_MODE=real SMS_DRIVER=http   HttpSmsSender (POST JSON configurable)
    */
    'sms' => [
        'mode' => env('SMS_MODE', 'fake'),
        'driver' => env('SMS_DRIVER', 'http'),
        'from' => env('SMS_FROM'),
        'http' => [
            'url' => env('SMS_HTTP_URL'),
            'token' => env('SMS_HTTP_TOKEN'),
            'auth_header' => 'Authorization',
            'auth_scheme' => 'Bearer',
            'to_field' => 'to',
            'body_field' => 'body',
            'from_field' => 'from',
            'id_path' => 'id',       // dónde está el id del mensaje en la respuesta JSON (notación con puntos)
            'extra' => [],           // campos fijos del cuerpo
            'timeout' => (int) env('SMS_HTTP_TIMEOUT', 10),
        ],
    ],

    /*
    | Webhooks ENTRANTES genéricos: POST /webhooks/inbound/{provider}.
    | Cada proveedor se registra en `providers` (ver docs/integrations.md §7).
    */
    'inbound_webhooks' => [
        'queue' => env('INBOUND_WEBHOOK_QUEUE', 'default'),
        // Tamaño máximo del cuerpo (bytes). Mayor => 413 sin leerlo entero.
        'max_bytes' => (int) env('INBOUND_WEBHOOK_MAX_BYTES', 1048576),
        // Peticiones por minuto, por IP y proveedor.
        'rate_limit_per_minute' => (int) env('INBOUND_WEBHOOK_RATE_LIMIT', 120),
        // Los recibos procesados se borran pasados estos días (el payload puede llevar datos personales).
        'retention_days' => (int) env('INBOUND_WEBHOOK_RETENTION_DAYS', 30),
        // Reintentos del job por recibo y esperas (segundos) entre ellos.
        'tries' => 5,
        'backoff' => [30, 120, 600, 1800],

        /*
        | Por proveedor:
        |   secret_config  clave de config() donde vive el secreto (nunca el secreto aquí)
        |   signature      ['driver' => 'svix'] | ['driver' => 'hmac', 'header' => ..., 'timestamp_header' => ...,
        |                   'prefix' => 'sha256=', 'encoding' => 'hex'|'base64', 'tolerance' => 300,
        |                   'signed_payload' => '{timestamp}.{body}'] | ['driver' => Clase::class]
        |   delivery_id    ['header' => 'x-id'] | ['payload' => 'id'] | null (hash del cuerpo)
        |   handler        clase que implementa InboundWebhookHandlerInterface
        |   rules          reglas de validación del payload (422 si no cumple)
        |   store_headers  cabeceras que se guardan en el recibo (las de firma NUNCA)
        |   tenant_resolver  clase InboundWebhookTenantResolverInterface (multiempresa) y `tenant_required`
        |   max_bytes      tope propio
        */
        'providers' => [
            'resend' => [
                'secret_config' => 'services.resend.webhook_secret',
                'signature' => ['driver' => 'svix'],
                'delivery_id' => ['header' => 'svix-id'],
                'handler' => ResendMailEventsHandler::class,
                'rules' => ['type' => 'required|string', 'data.email_id' => 'required|string'],
                'store_headers' => ['svix-id', 'svix-timestamp'],
            ],
        ],
    ],

];

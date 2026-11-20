<?php

/*
|--------------------------------------------------------------------------
| Idempotencia HTTP (docs/idempotency.md)
|--------------------------------------------------------------------------
|
| Middleware `idempotent` (opcional) / `idempotent:required` (la cabecera es obligatoria).
*/
return [
    'enabled' => (bool) env('IDEMPOTENCY_ENABLED', true),

    // Cabecera con la clave que envía el cliente.
    'header' => 'Idempotency-Key',

    // Solo estos métodos se vigilan; el resto pasa de largo.
    'methods' => ['POST', 'PATCH'],

    // Longitud máxima de la clave (caracteres ASCII imprimibles, sin espacios).
    'max_key_length' => 255,

    // Cuánto se conserva una respuesta guardada para reproducirla (horas).
    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),

    // Cuánto dura la reserva mientras la petición se ejecuta (segundos). Pasado ese tiempo se asume que el
    // proceso murió y otra petición con la misma clave puede tomarla. Debe superar la petición más lenta.
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 120),

    // Si la clave está en curso: esperar hasta N ms a que termine y devolver la respuesta guardada
    // (0 = responder 409 de inmediato). Bloquea el worker mientras espera: valores pequeños.
    'wait_ms' => (int) env('IDEMPOTENCY_WAIT_MS', 0),

    // Guardar también las respuestas 4xx (excepto 408, 425 y 429, que nunca se guardan). Las 5xx nunca.
    'store_client_errors' => (bool) env('IDEMPOTENCY_STORE_CLIENT_ERRORS', true),

    // Tamaño máximo del cuerpo de respuesta a guardar (bytes). Más grande: se ejecuta y no se guarda.
    'max_response_bytes' => (int) env('IDEMPOTENCY_MAX_RESPONSE_BYTES', 65536),

    // Cabeceras de respuesta que NO se guardan (minúsculas). Además se omiten las propias de cada respuesta.
    'sensitive_headers' => [
        'set-cookie', 'authorization', 'proxy-authorization', 'x-request-id', 'x-trace-id',
        'date', 'content-length', 'content-encoding', 'etag', 'x-ratelimit-limit', 'x-ratelimit-remaining',
        'retry-after',
    ],
];

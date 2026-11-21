<?php

/*
|--------------------------------------------------------------------------
| Métricas Prometheus (módulo opcional `metrics`; docs/metrics.md)
|--------------------------------------------------------------------------
|
| `GET /metrics` en formato de texto de Prometheus. APAGADO por defecto: con `METRICS_ENABLED=false` la ruta
| responde 404 y el middleware no registra nada. Aunque esté encendido, NUNCA es público: exige `METRICS_TOKEN`
| (cabecera `Authorization: Bearer <token>`) o que la IP esté en `METRICS_ALLOWED_IPS` (lista separada por comas,
| admite CIDR). Si no hay ni token ni IPs configurados, responde siempre 403.
*/
return [
    'enabled' => (bool) env('METRICS_ENABLED', false),

    'token' => env('METRICS_TOKEN'),

    /** @var list<string> */
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('METRICS_ALLOWED_IPS', ''))))),

    // Prefijo de los nombres de métrica (`<namespace>_http_requests_total`).
    'namespace' => env('METRICS_NAMESPACE', 'app'),

    // Conexión Redis (config/database.php) donde se acumulan los contadores y el prefijo de sus claves.
    'redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),
    'key_prefix' => env('METRICS_KEY_PREFIX', 'metrics:'),

    // Cubos del histograma de latencia, en segundos.
    'buckets' => [0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],

    // Colas cuyo tamaño se expone (conexión por defecto de `config/queue.php`).
    'queues' => array_values(array_filter(array_map('trim', explode(',', (string) env('METRICS_QUEUES', 'default'))))),
];

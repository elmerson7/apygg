<?php

return [

    /*
    | Modo mantenimiento de la API (docs/maintenance-mode.md). El estado vive en la CACHÉ compartida (Redis en
    | producción), no en un archivo local: todas las réplicas y los workers de Octane lo ven a la vez.
    */
    'store' => env('MAINTENANCE_CACHE_STORE'), // null = la caché por defecto

    'cache_key' => 'maintenance:state',

    /*
    | Forzarlo por entorno (despliegues): MAINTENANCE_ENABLED=true. Gana sobre la caché y no se puede desactivar
    | por la API; mensaje y fin estimado opcionales.
    */
    'enabled' => env('MAINTENANCE_ENABLED', false),
    'message' => env('MAINTENANCE_MESSAGE'),
    'until' => env('MAINTENANCE_UNTIL'), // fecha ISO 8601

    /*
    | `Retry-After` por defecto (segundos) si no hay fin estimado.
    */
    'retry_after' => (int) env('MAINTENANCE_RETRY_AFTER', 60),

    /*
    | Siempre accesibles (patrones de `Request::is`, sin `/` inicial): salud y webhooks entrantes (los proveedores
    | reintentan, pero perder eventos es peor que atenderlos).
    */
    'allow_paths' => [
        'health',
        'health/*',
        'webhooks/inbound/*',
        'webhooks/mail',
    ],

    /*
    | Quién puede probar la API en mantenimiento: IPs o CIDR (separadas por comas) y roles (token válido).
    */
    'allow_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAINTENANCE_ALLOW_IPS', ''))))),
    'allow_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAINTENANCE_ALLOW_ROLES', 'admin'))))),
];

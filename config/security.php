<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración de seguridad: IP whitelisting, blacklisting, etc.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Lista de IPs permitidas para endpoints críticos.
    | Soporta IPs individuales y rangos CIDR (ej: 192.168.1.0/24).
    |
    | Si está vacío, todas las IPs están permitidas (comportamiento por defecto).
    | Para endpoints críticos, se puede configurar una whitelist específica.
    |
    */

    'ip_whitelist' => env('SECURITY_IP_WHITELIST', '')
        ? explode(',', (string) env('SECURITY_IP_WHITELIST'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | IP Blacklist
    |--------------------------------------------------------------------------
    |
    | Lista de IPs bloqueadas globalmente.
    | Estas IPs serán rechazadas sin importar otros permisos.
    |
    */

    'ip_blacklist' => env('SECURITY_IP_BLACKLIST', '')
        ? explode(',', (string) env('SECURITY_IP_BLACKLIST'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Endpoints Críticos con IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Rutas que requieren IP whitelist para acceder.
    | Se pueden especificar rutas específicas o patrones.
    |
    | Ejemplo:
    | 'critical_endpoints' => [
    |     'admin/*',
    *     'api/webhooks/*',
    * ],
    |
    */

    'critical_endpoints' => [
        // 'admin/*',
        // 'api/webhooks/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Whitelist por Endpoint
    |--------------------------------------------------------------------------
    |
    | Configuración de whitelist específica por endpoint.
    | Permite tener diferentes whitelists para diferentes rutas.
    |
    | Ejemplo:
    | 'endpoint_whitelists' => [
    |     'admin/*' => ['192.168.1.0/24', '10.0.0.1'],
    *     'api/webhooks/*' => ['203.0.113.0/24'],
    * ],
    |
    */

    'endpoint_whitelists' => [
        // 'admin/*' => ['192.168.1.0/24'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging de Intentos Bloqueados
    |--------------------------------------------------------------------------
    |
    | Si está habilitado, se registrarán en SecurityLog todos los intentos
    * de acceso desde IPs no permitidas.
    |
    */

    'log_blocked_attempts' => env('SECURITY_LOG_BLOCKED_ATTEMPTS', true),

    /*
    |--------------------------------------------------------------------------
    | Mensaje de Error
    |--------------------------------------------------------------------------
    |
    | Mensaje que se devuelve cuando se bloquea una IP.
    |
    */

    'blocked_message' => 'Acceso denegado desde esta dirección IP.',

    /*
    |--------------------------------------------------------------------------
    | Cloudflare — Resolución de IP Real
    |--------------------------------------------------------------------------
    |
    | Cuando la app corre detrás de Cloudflare, la IP del peer es la del proxy.
    | ResolveClientIpMiddleware reemplaza REMOTE_ADDR por la cabecera
    | CF-Connecting-IP, pero SOLO si el peer pertenece a un rango oficial de
    | Cloudflare (si no, cualquiera podría falsificar su IP).
    |
    | Deja 'ipv4'/'ipv6' vacíos para usar los rangos por defecto del middleware.
    | Rangos oficiales actualizados: https://www.cloudflare.com/ips/
    |
    */

    'cloudflare' => [
        'enabled' => env('SECURITY_CLOUDFLARE_ENABLED', true),
        'ipv4' => [],
        'ipv6' => [],
    ],

];

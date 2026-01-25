<?php

namespace App\Http\Middleware;

use App\Services\LogService;
use App\Services\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IpWhitelistMiddleware
 *
 * Middleware para restringir acceso a endpoints críticos solo desde IPs permitidas.
 * Utiliza SecurityService para verificar IPs y soporta rangos CIDR.
 *
 * @package App\Http\Middleware
 */
class IpWhitelistMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $path = $request->path();

        // Verificar si la IP está en blacklist global
        if ($this->isIpBlacklisted($ip)) {
            $this->logBlockedAttempt($request, $ip, 'IP está en blacklist global');
            return $this->buildBlockedResponse();
        }

        // Verificar si la ruta requiere whitelist
        if (!$this->requiresWhitelist($path)) {
            return $next($request);
        }

        // Obtener whitelist específica para este endpoint o usar la global
        $whitelist = $this->getWhitelistForEndpoint($path);

        // Verificar si la IP está permitida
        if (!SecurityService::isIpWhitelisted($ip, $whitelist)) {
            $this->logBlockedAttempt($request, $ip, 'IP no está en whitelist');
            return $this->buildBlockedResponse();
        }

        return $next($request);
    }

    /**
     * Verificar si la IP está en blacklist global
     *
     * @param string $ip
     * @return bool
     */
    protected function isIpBlacklisted(string $ip): bool
    {
        $blacklist = config('security.ip_blacklist', []);

        if (empty($blacklist)) {
            return false;
        }

        foreach ($blacklist as $blockedIp) {
            // Soporte para rangos CIDR
            if (str_contains($blockedIp, '/')) {
                if ($this->ipInRange($ip, $blockedIp)) {
                    return true;
                }
            } elseif ($ip === $blockedIp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si IP está en rango CIDR
     *
     * @param string $ip
     * @param string $range Rango CIDR (ej: 192.168.1.0/24)
     * @return bool
     */
    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Verificar si la ruta requiere whitelist
     *
     * @param string $path
     * @return bool
     */
    protected function requiresWhitelist(string $path): bool
    {
        $criticalEndpoints = config('security.critical_endpoints', []);

        if (empty($criticalEndpoints)) {
            return false; // Si no hay endpoints críticos configurados, no requiere whitelist
        }

        foreach ($criticalEndpoints as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener whitelist específica para el endpoint o usar la global
     *
     * @param string $path
     * @return array|null
     */
    protected function getWhitelistForEndpoint(string $path): ?array
    {
        $endpointWhitelists = config('security.endpoint_whitelists', []);

        foreach ($endpointWhitelists as $pattern => $whitelist) {
            if ($this->matchesPattern($path, $pattern)) {
                return $whitelist;
            }
        }

        // Si no hay whitelist específica, usar la global
        $globalWhitelist = config('security.ip_whitelist', []);
        return !empty($globalWhitelist) ? $globalWhitelist : null;
    }

    /**
     * Verificar si la ruta coincide con un patrón
     *
     * @param string $path
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Convertir patrón wildcard a regex
        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));

        return (bool) preg_match("/^{$pattern}$/", $path);
    }

    /**
     * Registrar intento bloqueado
     *
     * @param Request $request
     * @param string $ip
     * @param string $reason
     * @return void
     */
    protected function logBlockedAttempt(Request $request, string $ip, string $reason): void
    {
        if (!config('security.log_blocked_attempts', true)) {
            return;
        }

        try {
            LogService::logSecurity('ip_blocked', "Intento de acceso bloqueado: {$reason}", [
                'ip' => $ip,
                'path' => $request->path(),
                'method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'reason' => $reason,
            ]);
        } catch (\Exception $e) {
            // Silenciar errores de logging
        }
    }

    /**
     * Construir respuesta de acceso bloqueado
     *
     * @return Response
     */
    protected function buildBlockedResponse(): Response
    {
        $message = config('security.blocked_message', 'Acceso denegado desde esta dirección IP.');

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'type' => 'access_denied',
                'code' => 'IP_NOT_ALLOWED',
            ],
        ], 403);
    }
}

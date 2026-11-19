<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveClientIpMiddleware
 *
 * Resuelve la IP real del cliente cuando la aplicación está detrás de Cloudflare.
 *
 * Sin este middleware, `$request->ip()` devuelve la IP del proxy de Cloudflare,
 * lo que rompe el rate limiting, el IP whitelist y los logs de seguridad
 * (todos los requests parecerían venir de la misma IP).
 *
 * La cabecera `CF-Connecting-IP` solo se acepta si el peer real pertenece a un
 * rango oficial de Cloudflare: de lo contrario cualquiera podría falsificar su
 * IP enviando esa cabecera a mano.
 *
 * Rangos oficiales: https://www.cloudflare.com/ips/
 * Pueden sobreescribirse en `config/security.php` → `cloudflare.ipv4/ipv6`.
 */
class ResolveClientIpMiddleware
{
    /**
     * Rangos IPv4 oficiales de Cloudflare (fallback si no hay config).
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_IPV4_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Rangos IPv6 oficiales de Cloudflare (fallback si no hay config).
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_IPV6_RANGES = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.cloudflare.enabled', true)) {
            return $next($request);
        }

        $clientIp = $request->header('CF-Connecting-IP');

        // El peer es la conexión TCP real (REMOTE_ADDR). `getClientIp()` NO sirve
        // aquí: con TRUSTED_PROXIES mal configurado devuelve la IP que diga
        // X-Forwarded-For, y un atacante podría hacerse pasar por Cloudflare.
        $peerIp = $request->server->get('REMOTE_ADDR');

        if ($clientIp && $this->isCloudflareIp($peerIp) && $this->isValidIp($clientIp)) {
            $request->server->set('REMOTE_ADDR', $clientIp);
        }

        return $next($request);
    }

    /**
     * Verificar si la IP pertenece a un rango oficial de Cloudflare
     */
    private function isCloudflareIp(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        // Los rangos de config tienen prioridad; si están vacíos se usan los del
        // middleware (config() devolvería el array vacío, no el default).
        $ranges = str_contains($ip, ':')
            ? (config('security.cloudflare.ipv6') ?: self::CLOUDFLARE_IPV6_RANGES)
            : (config('security.cloudflare.ipv4') ?: self::CLOUDFLARE_IPV4_RANGES);

        foreach ($ranges as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar que la IP recibida por cabecera sea sintácticamente válida
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

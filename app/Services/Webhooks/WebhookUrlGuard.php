<?php

namespace App\Services\Webhooks;

use App\Contracts\HostResolverInterface;
use App\Exceptions\BusinessLogicException;

/**
 * WebhookUrlGuard
 *
 * Defensa contra SSRF en las URLs de los webhooks salientes. Se usa al crear/actualizar
 * (regla `SafeWebhookUrl`) y OTRA VEZ justo antes de cada envío (el DNS puede haber
 * cambiado desde el alta). Resuelve el nombre y bloquea la URL si CUALQUIER IP resuelta es
 * privada, loopback, link-local (incluye metadata cloud 169.254.169.254), CGNAT, multicast,
 * reservada o IPv6 local. El envío fija la IP validada (CURLOPT_RESOLVE) para que un
 * DNS-rebinding no pueda cambiarla entre la comprobación y la conexión.
 *
 * Configuración en `config/webhooks.php` → `ssrf`.
 */
class WebhookUrlGuard
{
    /** Rangos bloqueados (IPv4 e IPv6) en notación CIDR. */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private readonly HostResolverInterface $resolver) {}

    /**
     * Valida la URL y devuelve los datos para conectar con seguridad.
     *
     * @return array{scheme: string, host: string, port: int, ip: string, is_literal: bool}
     *
     * @throws BusinessLogicException con el motivo del rechazo (422)
     */
    public function inspect(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new BusinessLogicException('La URL no es válida.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowed = config('webhooks.ssrf.allow_http', false) ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowed, true)) {
            throw new BusinessLogicException('La URL debe usar '.(count($allowed) > 1 ? 'http o https.' : 'https.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BusinessLogicException('La URL no puede incluir usuario ni contraseña.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $trusted = in_array($host, array_map('strtolower', (array) config('webhooks.ssrf.allowed_hosts', [])), true);

        $ips = $isLiteral ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new BusinessLogicException('No se pudo resolver el dominio de la URL.');
        }

        if (! $trusted) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip)) {
                    throw new BusinessLogicException('La URL apunta a una dirección interna o no permitida.');
                }
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'is_literal' => $isLiteral];
    }

    public static function isBlockedIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return true; // no es una IP interpretable: no se arriesga
        }

        // IPv4 embebida en IPv6 (::ffff:a.b.c.d y NAT64 64:ff9b::/96): se evalúa la IPv4 real
        if (strlen($binary) === 16
            && (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") || str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)))) {
            $embedded = inet_ntop(substr($binary, 12));

            return $embedded === false || self::isBlockedIp($embedded);
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $binaryIp, string $cidr): bool
    {
        [$range, $bits] = explode('/', $cidr);
        $rangeBinary = inet_pton($range);

        if ($rangeBinary === false || strlen($rangeBinary) !== strlen($binaryIp)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && substr($binaryIp, 0, $bytes) !== substr($rangeBinary, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binaryIp[$bytes]) & $mask) === (ord($rangeBinary[$bytes]) & $mask);
    }
}

<?php

namespace App\Services\Webhooks;

use App\Contracts\HostResolverInterface;

/**
 * Resolución DNS real (A y AAAA).
 */
class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}

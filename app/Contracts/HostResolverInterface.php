<?php

namespace App\Contracts;

/**
 * HostResolverInterface
 *
 * Resuelve un nombre de host a sus direcciones IP. Existe para poder sustituir
 * el DNS real en los tests (binding en el contenedor, sin estado estático).
 */
interface HostResolverInterface
{
    /**
     * @return list<string> IPs (v4 y v6) del host; vacío si no resuelve
     */
    public function resolve(string $host): array;
}

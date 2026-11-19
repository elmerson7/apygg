<?php

namespace Tests\Support;

/**
 * Varias identidades en un mismo test: el guard JWT conserva el usuario y el token de la petición
 * anterior (instancias singleton), y `withHeaders` acumula cabeceras. `asHeaders()` limpia todo
 * y fija solo las cabeceras dadas (las cabeceras se calculan ANTES de llamarlo).
 */
trait ActsWithHeaders
{
    /**
     * @param  array<string, string>  $headers
     */
    public function asHeaders(array $headers): static
    {
        auth()->forgetGuards();
        app('tymon.jwt')->unsetToken();
        app('tymon.jwt.auth')->unsetToken();

        return $this->flushHeaders()->withHeaders($headers);
    }
}

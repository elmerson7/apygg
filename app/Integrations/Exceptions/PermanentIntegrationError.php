<?php

namespace App\Integrations\Exceptions;

/** HTTP 4xx (salvo 408/429), credenciales ausentes o dato inválido: es un error nuestro, no se reintenta. */
class PermanentIntegrationError extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}

<?php

namespace App\Integrations\Exceptions;

/** Timeout, error de conexión, HTTP 5xx o 408: el mismo intento puede funcionar más tarde. */
class TransientIntegrationError extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}

<?php

namespace App\Integrations\Exceptions;

/** Cuota o saldo agotados (HTTP 402, «sin crédito»): reintentar no ayuda hasta que alguien actúe. */
class QuotaExceededError extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}

<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Escritura bloqueada por el estado de la suscripción del tenant (docs/billing.md §Bloqueo por estado). 402 con `code`
 * estable (`payment_required`, `subscription_inactive` o `subscription_required`) y la acción sugerida para el cliente.
 */
class SubscriptionBlockedException extends ApiException
{
    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(string $message, private readonly string $stableCode, array $extensions = [])
    {
        parent::__construct($message, 402, [], $stableCode, null, $extensions);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }
}

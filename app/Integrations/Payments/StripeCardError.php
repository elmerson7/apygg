<?php

namespace App\Integrations\Payments;

use RuntimeException;

/**
 * Interno de `StripePaymentGateway`: un rechazo del banco (HTTP 402 `card_error`) que `charge()` convierte en
 * `ChargeResult(false, ...)`. No es un error de integración y nunca sale de la pasarela.
 */
final class StripeCardError extends RuntimeException
{
    public function __construct(public readonly ChargeResult $result)
    {
        parent::__construct('Rechazo del banco');
    }
}

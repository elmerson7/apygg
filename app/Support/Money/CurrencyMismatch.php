<?php

namespace App\Support\Money;

use App\Exceptions\BusinessLogicException;

/** Operación entre importes de monedas distintas (422 como error de negocio). */
final class CurrencyMismatch extends BusinessLogicException
{
    public static function between(string $a, string $b): self
    {
        return new self("No se pueden combinar importes de monedas distintas ({$a} y {$b}).", 'currency_mismatch');
    }
}

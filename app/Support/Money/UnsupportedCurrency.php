<?php

namespace App\Support\Money;

use InvalidArgumentException;

final class UnsupportedCurrency extends InvalidArgumentException
{
    public static function for(string $code): self
    {
        return new self("Moneda no admitida: [{$code}]. Añádela a config/currencies.php con su exponente.");
    }
}

<?php

namespace App\Support\Billing;

use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonInterface;

/** Línea de factura por emitir (`InvoiceServiceInterface::issue`). `unitAmount` lleva signo: un crédito es negativo. */
final readonly class LineSpec
{
    public function __construct(
        public string $type,
        public string $description,
        public Money $unitAmount,
        public int $quantity = 1,
        public ?CarbonInterface $periodStart = null,
        public ?CarbonInterface $periodEnd = null,
    ) {}

    public function amount(): Money
    {
        return $this->unitAmount->multiply($this->quantity, Rounding::HalfUp);
    }
}

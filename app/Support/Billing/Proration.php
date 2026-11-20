<?php

namespace App\Support\Billing;

use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonInterface;

/**
 * Prorrateo de un cambio de plan inmediato (docs/billing.md §Prorrateo). Todo en enteros de la unidad menor:
 * la fracción del periodo restante se calcula con bcmath (12 decimales) y cada línea se redondea UNA vez
 * (mitad hacia fuera de cero), así que `neto = cargo - crédito` es exacto y coincide con la suma de las líneas.
 */
final readonly class Proration
{
    public function __construct(
        public Money $credit,   // lo no usado del plan actual (se resta)
        public Money $charge,   // lo que queda del periodo al precio del plan nuevo (se suma)
        public Money $net,      // charge - credit
        public string $ratio,   // fracción del periodo restante, "0.456789012345"
    ) {}

    public static function calculate(Money $current, Money $new, CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $now): self
    {
        $total = max(1, $periodEnd->getTimestamp() - $periodStart->getTimestamp());
        $remaining = max(0, min($total, $periodEnd->getTimestamp() - $now->getTimestamp()));
        $ratio = bcdiv((string) $remaining, (string) $total, 12);

        $credit = $current->multiply($ratio, Rounding::HalfUp);
        $charge = $new->multiply($ratio, Rounding::HalfUp);

        return new self($credit, $charge, $charge->subtract($credit), $ratio);
    }
}

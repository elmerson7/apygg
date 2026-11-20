<?php

namespace App\Enums;

/**
 * Estados de una factura. Una factura emitida (todo lo que no es `draft`) es inmutable: solo cambia de estado.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Void],
            self::Open => [self::Paid, self::Void, self::Uncollectible],
            // Una incobrable puede acabar cobrándose (pago manual tardío).
            self::Uncollectible => [self::Paid, self::Void],
            self::Paid, self::Void => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}

<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Cobro devuelto por la pasarela (la factura quedó anulada tras cobrarse). */
    case Refunded = 'refunded';
}

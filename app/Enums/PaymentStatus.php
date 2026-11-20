<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    /** 3DS/SCA: la pasarela espera que el cliente confirme en el front (cuenta como intento en curso). */
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Cobro devuelto por la pasarela (la factura quedó anulada tras cobrarse). */
    case Refunded = 'refunded';
}

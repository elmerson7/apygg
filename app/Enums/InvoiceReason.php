<?php

namespace App\Enums;

/**
 * Por qué existe una factura. `create` y `cycle` ocupan un periodo (único por suscripción); `update` es el
 * ajuste prorrateado de un cambio de plan inmediato.
 */
enum InvoiceReason: string
{
    case Create = 'subscription_create';
    case Cycle = 'subscription_cycle';
    case Update = 'subscription_update';
}

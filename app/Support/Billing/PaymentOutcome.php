<?php

namespace App\Support\Billing;

/** Resultado de intentar cobrar una factura (`PaymentProcessorInterface::collect`). */
enum PaymentOutcome: string
{
    /** Cobrada: la factura está `paid`. */
    case Paid = 'paid';
    /** Rechazada por la pasarela (o sin método de pago): quedó registrada y se aplicó la política de reintentos. */
    case Declined = 'declined';
    /** Error transitorio de la pasarela: el intento queda pendiente y se retoma con la misma clave de idempotencia. */
    case Pending = 'pending';
    /** La pasarela pide acción al cliente (3DS): el `client_secret` va al front; el resultado llegará por webhook. */
    case RequiresAction = 'requires_action';
    /** Otro proceso está cobrando esta factura ahora mismo (arrendamiento vigente): no se hace nada. */
    case InProgress = 'in_progress';
    /** La factura ya no está abierta (pagada, anulada...) o su suscripción terminó: nada que cobrar. */
    case Skipped = 'skipped';
    /**
     * La pasarela cobró, pero la factura se anuló mientras tanto (baja inmediata, alta caducada o ajuste de plan que ya no
     * aplica): el cobro NO cuenta y se reembolsa (`PaymentProcessorInterface::refund`, al momento o en el siguiente `billing:renew`).
     */
    case Voided = 'voided';
    /**
     * El resultado es de OTRO PaymentIntent que el que ya tiene registrado este intento local (otro proceso o webhook lo
     * fijó entre medias). No se aplica; si cobró, queda como discrepancia `duplicate_charge` para devolverlo.
     */
    case OtherIntent = 'other_intent';
}

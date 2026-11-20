<?php

namespace App\Support\Billing;

/** Qué hizo `RenewalServiceInterface::renew()` con una suscripción. */
enum RenewalOutcome: string
{
    case NotDue = 'not_due';
    /** Otro proceso la tenía bloqueada (`SKIP LOCKED`): ese proceso se encarga. */
    case Locked = 'locked';
    case Renewed = 'renewed';
    /** Plan gratuito: el periodo avanzó sin factura. */
    case RenewedFree = 'renewed_free';
    case PaymentFailed = 'payment_failed';
    case Retrying = 'retrying';
    case InProgress = 'in_progress';
    /** El cobro espera la confirmación 3DS del cliente. */
    case ActionRequired = 'action_required';
    case Canceled = 'canceled';
    case Expired = 'expired';
    /** Se resolvió un cobro que había quedado a medias sobre una factura ya anulada: el cargo se reembolsa. */
    case ChargeVoided = 'charge_voided';
}

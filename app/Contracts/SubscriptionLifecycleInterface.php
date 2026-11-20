<?php

namespace App\Contracts;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Carbon\CarbonInterface;

/**
 * Reglas de estado de una suscripción (docs/billing.md §Estados). INTERNO: lo usan los servicios de facturación
 * y SIEMPRE dentro de una transacción con la fila de la suscripción bloqueada (`FOR UPDATE`).
 */
interface SubscriptionLifecycleInterface
{
    /**
     * Único punto que cambia `subscriptions.status`. Valida contra `SubscriptionStatus::allowedTransitions()`,
     * audita, refresca los límites del plan y, si es terminal, despacha `SubscriptionCanceled`.
     *
     * @param  array<string, mixed>  $context  datos extra para la auditoría
     *
     * @throws BillingException `invalid_subscription_transition` (409)
     */
    public function transition(Subscription $subscription, SubscriptionStatus $to, string $cause, array $context = []): void;

    /** Copia plan, intervalo, moneda e importe del precio a la suscripción (los congela). Despacha `SubscriptionPlanChanged` si cambia el código. */
    public function applyPrice(Subscription $subscription, PlanPrice $price): void;

    /** Efectos de una factura pagada (activar, avanzar el periodo, aplicar el cambio de plan). Ignora suscripciones terminadas. */
    public function onInvoicePaid(Subscription $subscription, Invoice $invoice): void;

    /**
     * ¿Sigue valiendo el ajuste de una factura `subscription_update`? Falso si la suscripción cambió mientras se cobraba
     * (renovación, otro cambio de plan, baja): entonces el cobro no debe quedarse y la factura se anula para reembolsarlo.
     */
    public function updateStillApplies(Subscription $subscription, Invoice $invoice): bool;

    /**
     * Efectos de un cobro fallido: `past_due` y calendario de reintentos (renovación), o nada (alta), o anular el ajuste (cambio de plan).
     * `$counts` = false para un intento manual del cliente: no consume reintentos del calendario.
     *
     * @return CarbonInterface|null fecha del próximo reintento automático; null si no habrá más
     */
    public function onPaymentFailed(Subscription $subscription, Invoice $invoice, string $failureCode, bool $counts = true): ?CarbonInterface;
}

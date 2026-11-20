<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\SubscriptionCanceled;
use App\Events\Billing\SubscriptionPlanChanged;
use App\Events\Billing\SubscriptionRenewed;
use App\Events\Billing\SubscriptionStarted;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\LogService;
use Carbon\CarbonInterface;

/**
 * Reglas de estado de la suscripción. Todo cambio de `status` pasa por `transition()`: valida contra
 * `SubscriptionStatus::allowedTransitions()`, deja rastro en la auditoría (`billing.subscription-status-changed`),
 * refresca los límites del plan y avisa con eventos (después del commit). Sin estado entre llamadas (Octane).
 */
class SubscriptionLifecycle implements SubscriptionLifecycleInterface
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly PlanLimitsInterface $limits,
    ) {}

    public function transition(Subscription $subscription, SubscriptionStatus $to, string $cause, array $context = []): void
    {
        $from = $subscription->status;

        if (! $from->canTransitionTo($to)) {
            throw BillingException::invalidTransition($from, $to);
        }

        $subscription->status = $to;

        if ($to->isTerminal()) {
            $subscription->ended_at = now();
            $subscription->next_retry_at = null;
        }

        $subscription->save();

        $this->audit->recordAction('billing.subscription-status-changed', $subscription, null, ['from' => $from->value, 'to' => $to->value, 'cause' => $cause, ...$context], $subscription->tenant_id);
        $this->limits->refresh($subscription->tenant_id);

        if ($to->isTerminal()) {
            SubscriptionCanceled::dispatch($subscription->id, $subscription->tenant_id, $to->value, $cause);
        }
    }

    public function applyPrice(Subscription $subscription, PlanPrice $price): void
    {
        $price->loadMissing('plan');
        $plan = $price->plan;
        $previous = $subscription->plan_code;

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'plan_code' => $plan->code,
            'interval' => $price->interval,
            'currency' => $price->currency,
            'unit_amount' => $price->unit_amount,
        ]);

        if ($subscription->scheduled_plan_price_id === $price->id) {
            $subscription->scheduled_plan_price_id = null;
        }

        $subscription->save();

        $this->audit->recordAction('billing.subscription-plan-changed', $subscription, null, ['from_plan' => $previous, 'to_plan' => $plan->code, 'unit_amount_minor' => $price->unit_amount->amountMinor, 'currency' => $price->currency, 'interval' => $price->interval->value], $subscription->tenant_id);
        $this->limits->refresh($subscription->tenant_id);

        if ($previous !== $plan->code) {
            SubscriptionPlanChanged::dispatch($subscription->id, $subscription->tenant_id, $previous, $plan->code);
        }
    }

    public function onInvoicePaid(Subscription $subscription, Invoice $invoice): void
    {
        // Una factura incobrable que se paga tarde (pago manual) no resucita una suscripción terminada.
        if (! $subscription->status->isLive()) {
            return;
        }

        match ($invoice->billing_reason) {
            InvoiceReason::Create => $this->afterCreatePaid($subscription, $invoice),
            InvoiceReason::Cycle => $this->afterCyclePaid($subscription, $invoice),
            InvoiceReason::Update => $this->afterUpdatePaid($subscription, $invoice),
        };
    }

    public function onPaymentFailed(Subscription $subscription, Invoice $invoice, string $failureCode, bool $counts = true): ?CarbonInterface
    {
        $subscription->last_failure_code = $failureCode;

        if ($invoice->billing_reason === InvoiceReason::Update) {
            // El ajuste prorrateado no se cobró: se anula y el plan no cambia. No afecta a la morosidad.
            $this->voidInvoice($invoice);
            $subscription->save();

            return null;
        }

        if ($invoice->billing_reason === InvoiceReason::Create) {
            // Primer pago: la suscripción sigue `incomplete` (caduca sola); el cliente puede reintentar con otra tarjeta.
            $subscription->save();

            return null;
        }

        if (! $counts || ! $subscription->status->isLive()) {
            $subscription->save();

            return $subscription->next_retry_at;
        }

        $subscription->renewal_attempts++;
        $schedule = array_values(array_map('intval', (array) config('billing.dunning.retry_days', [1, 3, 5])));
        $attempt = $subscription->renewal_attempts;

        if ($attempt <= count($schedule)) {
            $next = now()->addDays($schedule[$attempt - 1]);
            $subscription->next_retry_at = $next;

            if ($subscription->status === SubscriptionStatus::PastDue) {
                $subscription->save();
            } else {
                $this->transition($subscription, SubscriptionStatus::PastDue, 'payment_failed', ['failure_code' => $failureCode, 'attempt' => $attempt]);
            }

            return $next;
        }

        // Reintentos agotados.
        $final = config('billing.dunning.final_status') === 'expired' ? SubscriptionStatus::Expired : SubscriptionStatus::Canceled;

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            $this->transition($subscription, SubscriptionStatus::PastDue, 'payment_failed', ['failure_code' => $failureCode, 'attempt' => $attempt]);
        }

        if ($invoice->status === InvoiceStatus::Open) {
            $invoice->status = InvoiceStatus::Uncollectible;
            $invoice->save();
        }

        $this->transition($subscription, $final, 'dunning_exhausted', ['failure_code' => $failureCode, 'attempt' => $attempt]);

        return null;
    }

    private function afterCreatePaid(Subscription $subscription, Invoice $invoice): void
    {
        $subscription->forceFill([
            'current_period_start' => $invoice->period_start,
            'current_period_end' => $invoice->period_end,
            'renewal_attempts' => 0,
            'next_retry_at' => null,
            'last_failure_code' => null,
        ]);

        if ($subscription->status === SubscriptionStatus::Incomplete) {
            $this->transition($subscription, SubscriptionStatus::Active, 'first_payment');
            SubscriptionStarted::dispatch($subscription->id, $subscription->tenant_id, $subscription->plan_code, SubscriptionStatus::Active->value);
        } else {
            $subscription->save();
        }
    }

    private function afterCyclePaid(Subscription $subscription, Invoice $invoice): void
    {
        $billed = $invoice->plan_price_id !== null && $invoice->plan_price_id !== $subscription->plan_price_id
            ? PlanPrice::query()->find($invoice->plan_price_id)
            : null;

        if ($billed instanceof PlanPrice) {
            $this->applyPrice($subscription, $billed);   // bajada programada que llega, o precio facturado distinto
        } elseif ($subscription->scheduled_plan_price_id !== null && $subscription->scheduled_plan_price_id === $invoice->plan_price_id) {
            $subscription->scheduled_plan_price_id = null;
        }

        $subscription->forceFill([
            'current_period_start' => $invoice->period_start,
            'current_period_end' => $invoice->period_end,
            'renewal_attempts' => 0,
            'next_retry_at' => null,
            'last_failure_code' => null,
        ]);

        if ($subscription->status === SubscriptionStatus::Active) {
            $subscription->save();
        } else {
            $this->transition($subscription, SubscriptionStatus::Active, 'payment_recovered');
        }

        SubscriptionRenewed::dispatch($subscription->id, $subscription->tenant_id, $invoice->id);
    }

    public function updateStillApplies(Subscription $subscription, Invoice $invoice): bool
    {
        $meta = (array) $invoice->metadata;

        return $invoice->billing_reason === InvoiceReason::Update
            && $invoice->plan_price_id !== null
            && $subscription->status === SubscriptionStatus::Active
            && (int) ($meta['from_plan_price_id'] ?? 0) === $subscription->plan_price_id
            && (string) ($meta['period_start'] ?? '') === $subscription->current_period_start->toIso8601String()
            && PlanPrice::query()->whereKey($invoice->plan_price_id)->exists();
    }

    private function afterUpdatePaid(Subscription $subscription, Invoice $invoice): void
    {
        $target = $invoice->plan_price_id !== null ? PlanPrice::query()->find($invoice->plan_price_id) : null;

        if ($target instanceof PlanPrice && $this->updateStillApplies($subscription, $invoice)) {
            $this->applyPrice($subscription, $target);

            return;
        }

        // Solo por un pago MANUAL de un ajuste cuyo contexto ya cambió (el cobro por la pasarela en ese caso anula la
        // factura y reembolsa: PaymentProcessor::settle). Queda en la auditoría para revisarlo.
        LogService::warning('billing: ajuste por cambio de plan cobrado con la suscripción ya distinta; requiere revisión/reembolso', [
            'invoice_id' => $invoice->id, 'subscription_id' => $subscription->id,
        ], 'security');
        $this->audit->recordAction('billing.stale-upgrade-payment', $invoice, null, ['subscription_id' => $subscription->id], $subscription->tenant_id);
    }

    private function voidInvoice(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Open) {
            $invoice->status = InvoiceStatus::Void;
            $invoice->voided_at = now();
            $invoice->save();
        }
    }
}

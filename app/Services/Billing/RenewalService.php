<?php

namespace App\Services\Billing;

use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\SubscriptionRenewed;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Support\Billing\LineSpec;
use App\Support\Billing\PaymentOutcome;
use App\Support\Billing\PeriodCalculator;
use App\Support\Billing\RenewalOutcome;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de cobro. Seguridad ante procesos simultáneos, por capas:
 *
 * 1. Fase 1 (transacción): `FOR UPDATE SKIP LOCKED` sobre la suscripción; si otro proceso la tiene, este la salta.
 * 2. La factura del periodo es única por BD (`invoices_one_per_period`: subscription_id + period_start): si ya existe,
 *    se reutiliza en vez de crear otra.
 * 3. El cobro (`PaymentProcessor`) reserva un intento con arrendamiento y una clave de idempotencia: un segundo
 *    proceso ve el intento en curso y no cobra; si el primero murió, retoma la misma clave.
 */
class RenewalService implements RenewalServiceInterface
{
    public function __construct(
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentProcessorInterface $payments,
        private readonly SubscriptionLifecycleInterface $lifecycle,
    ) {}

    public function due(int $limit): array
    {
        $now = now();
        $expireBefore = $now->copy()->subHours((int) config('billing.incomplete_expire_hours', 24));

        $rows = TenantQuery::withoutTenant(fn () => DB::table('subscriptions')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($now, $expireBefore): void {
                $q->where(fn ($q) => $q->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])->where('current_period_end', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('status', SubscriptionStatus::PastDue->value)->where('next_retry_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('status', SubscriptionStatus::Incomplete->value)->where('created_at', '<=', $expireBefore))
                    // Cobro por la pasarela interrumpido o con error transitorio: hay que retomarlo.
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('invoices')->join('payments', 'payments.invoice_id', '=', 'invoices.id')
                        ->whereColumn('invoices.subscription_id', 'subscriptions.id')
                        ->where('payments.status', PaymentStatus::Pending->value)
                        ->where('payments.claimed_until', '<=', $now)
                        ->whereNull('payments.deleted_at'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'tenant_id']), 'barrido de renovaciones (billing:renew)');

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'tenant_id' => (int) $r->tenant_id])->all();
    }

    public function renew(int $subscriptionId): RenewalOutcome
    {
        $prepared = DB::transaction(fn () => $this->prepare($subscriptionId));

        if ($prepared instanceof RenewalOutcome) {
            return $prepared;
        }

        return match ($this->payments->collect($prepared)) {
            PaymentOutcome::Paid => RenewalOutcome::Renewed,
            PaymentOutcome::Declined => RenewalOutcome::PaymentFailed,
            PaymentOutcome::Pending => RenewalOutcome::Retrying,
            PaymentOutcome::InProgress => RenewalOutcome::InProgress,
            PaymentOutcome::Skipped => RenewalOutcome::NotDue,
            PaymentOutcome::Voided => RenewalOutcome::ChargeVoided,
        };
    }

    /**
     * Fase 1. Devuelve el resultado final (nada que cobrar) o el id de la factura que hay que cobrar.
     */
    private function prepare(int $subscriptionId): RenewalOutcome|int
    {
        $subscription = Subscription::query()->whereKey($subscriptionId)->lock('FOR UPDATE SKIP LOCKED')->first();

        if (! $subscription instanceof Subscription) {
            return Subscription::query()->whereKey($subscriptionId)->exists() ? RenewalOutcome::Locked : RenewalOutcome::NotDue;
        }

        $now = now();

        if ($subscription->status === SubscriptionStatus::Incomplete) {
            if ($subscription->created_at->gt($now->copy()->subHours((int) config('billing.incomplete_expire_hours', 24)))) {
                return RenewalOutcome::NotDue;
            }

            foreach (Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->lockForUpdate()->get() as $invoice) {
                $invoice->status = InvoiceStatus::Void;
                $invoice->voided_at = $now;
                $invoice->save();
            }

            $this->lifecycle->transition($subscription, SubscriptionStatus::Expired, 'incomplete_timeout');

            return RenewalOutcome::Expired;
        }

        $dueForPeriodEnd = in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)
            && $subscription->current_period_end->lte($now);

        if ($dueForPeriodEnd) {
            return $this->prepareCycle($subscription);
        }

        if ($subscription->status === SubscriptionStatus::PastDue && $subscription->next_retry_at !== null && $subscription->next_retry_at->lte($now)) {
            $open = Invoice::query()->where('subscription_id', $subscription->id)->where('billing_reason', InvoiceReason::Cycle->value)->where('status', InvoiceStatus::Open->value)->orderByDesc('id')->first();

            return $open instanceof Invoice ? $open->id : RenewalOutcome::NotDue;
        }

        // Cobro interrumpido o con error transitorio: se retoma con la misma clave, también si la factura se cerró después
        // (baja, alta caducada) y la suscripción ya terminó: la pasarela pudo cobrar y hay que saberlo para reembolsar.
        $stuck = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('claimed_until', '<=', $now)
            ->whereIn('invoice_id', Invoice::query()->where('subscription_id', $subscription->id)->select('id'))
            ->orderBy('id')
            ->first();

        return $stuck instanceof Payment ? $stuck->invoice_id : RenewalOutcome::NotDue;
    }

    private function prepareCycle(Subscription $subscription): RenewalOutcome|int
    {
        if ($subscription->cancel_at_period_end) {
            $this->lifecycle->transition($subscription, SubscriptionStatus::Canceled, 'period_end');

            return RenewalOutcome::Canceled;
        }

        $price = $subscription->scheduled_plan_price_id !== null ? PlanPrice::query()->with('plan')->find($subscription->scheduled_plan_price_id) : null;

        $amount = $price->unit_amount ?? $subscription->unit_amount;
        $interval = $price->interval ?? $subscription->interval;
        $start = $subscription->current_period_end;
        $end = PeriodCalculator::end($start, $subscription->billing_anchor_at, $interval);

        if ($amount->isZero()) {
            if ($price instanceof PlanPrice) {
                $this->lifecycle->applyPrice($subscription, $price);
            }

            $subscription->forceFill(['current_period_start' => $start, 'current_period_end' => $end, 'renewal_attempts' => 0, 'next_retry_at' => null]);

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $this->lifecycle->transition($subscription, SubscriptionStatus::Active, 'trial_ended');
            } else {
                $subscription->save();
            }

            SubscriptionRenewed::dispatch($subscription->id, $subscription->tenant_id, null);

            return RenewalOutcome::RenewedFree;
        }

        $existing = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $start)
            ->whereIn('billing_reason', [InvoiceReason::Create->value, InvoiceReason::Cycle->value])
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Invoice) {
            return $existing->status === InvoiceStatus::Open ? $existing->id : RenewalOutcome::NotDue;
        }

        $planName = $price !== null ? $price->plan->name : (string) ($subscription->plan->name ?? $subscription->plan_code);

        $invoice = $this->invoices->issue(
            $subscription, InvoiceReason::Cycle, $price ?? $subscription->price, $start, $end,
            [new LineSpec('subscription', $planName.' ('.($interval->value === 'year' ? 'anual' : 'mensual').')', $amount, 1, $start, $end)],
        );

        return $invoice->id;
    }
}

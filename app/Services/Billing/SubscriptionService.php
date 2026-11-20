<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\SubscriptionStarted;
use App\Exceptions\BillingException;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\LineSpec;
use App\Support\Billing\PeriodCalculator;
use App\Support\Billing\Proration;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones del tenant sobre su suscripción (docs/billing.md). Cada operación bloquea la fila del tenant
 * (alta) o de la suscripción (resto) con `FOR UPDATE`, así que dos peticiones a la vez se serializan; el cobro
 * se hace después, fuera de la transacción (`PaymentProcessor`). Sin estado entre llamadas (Octane).
 */
class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        private readonly PlanCatalogInterface $catalog,
        private readonly BillingAccountServiceInterface $accounts,
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentProcessorInterface $payments,
        private readonly SubscriptionLifecycleInterface $lifecycle,
        private readonly AuditServiceInterface $audit,
        private readonly PlanLimitsInterface $limits,
        private readonly TenantContext $tenants,
    ) {}

    public function current(Tenant $tenant, User $actor): ?Subscription
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.read');

        return Subscription::query()->with('plan')
            ->orderByRaw("CASE WHEN status IN ('trialing','active','past_due','incomplete') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    public function subscribe(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval, ?string $paymentToken): Subscription
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        return $this->start($tenant, $planCode, $currency, $interval, $paymentToken, null);
    }

    public function assignPlan(int $tenantId, User $actor, string $planCode, ?string $currency, BillingInterval $interval, string $reason): Subscription
    {
        $reason = (string) $this->audit->requireReason('billing.plan-assigned', $reason);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $assignedBy = ['actor_id' => (int) $actor->getKey(), 'reason' => $reason];

        return $this->tenants->run($tenant, function () use ($tenant, $planCode, $currency, $interval, $assignedBy): Subscription {
            $live = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->exists();

            return $live
                ? $this->change($planCode, $currency, $interval, $assignedBy)
                : $this->start($tenant, $planCode, $currency, $interval, null, $assignedBy);
        });
    }

    /**
     * Alta (tenant o plataforma). `$assignedBy` (actor y motivo) = la asigna la plataforma: admite planes privados y se audita.
     *
     * @param  array{actor_id: int, reason: string}|null  $assignedBy
     */
    private function start(Tenant $tenant, string $planCode, ?string $currency, BillingInterval $interval, ?string $paymentToken, ?array $assignedBy): Subscription
    {
        $price = $this->catalog->findPrice($planCode, $currency ?? (string) config('billing.default_currency', 'USD'), $interval);

        if ($assignedBy === null && ! $price->plan->is_public) {
            throw BillingException::planPrivate();
        }

        // Cliente y método de pago en la pasarela ANTES de abrir la transacción (llamadas externas sin bloqueos).
        if ($paymentToken !== null && ! $price->unit_amount->isZero()) {
            $this->accounts->attach($tenant, $paymentToken);
        }

        [$subscriptionId, $invoiceId] = DB::transaction(function () use ($tenant, $price, $assignedBy): array {
            // Serializa las altas del mismo tenant; el índice único parcial es la red de seguridad en BD.
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            if (Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->exists()) {
                throw BillingException::subscriptionExists();
            }

            $plan = $price->plan;
            $now = CarbonImmutable::now();
            $free = $price->unit_amount->isZero();
            $hadTrial = Subscription::query()->withTrashed()->whereNotNull('trial_ends_at')->exists();
            $trial = ! $free && $plan->trial_days > 0 && ! $hadTrial;
            $hasMethod = (BillingAccount::query()->first())?->hasPaymentMethod() === true;

            if ($trial && config('billing.trial_requires_payment_method', false) && ! $hasMethod) {
                throw BillingException::paymentMethodRequired();
            }

            if (! $free && ! $trial && ! $hasMethod) {
                throw BillingException::paymentMethodRequired();
            }

            if ($trial) {
                $end = $now->addDays($plan->trial_days);
                $status = SubscriptionStatus::Trialing;
                $anchor = $end;
            } else {
                $anchor = $now;
                $end = PeriodCalculator::end($now, $anchor, $price->interval);
                $status = $free ? SubscriptionStatus::Active : SubscriptionStatus::Incomplete;
            }

            $subscription = new Subscription;
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'plan_price_id' => $price->id,
                'plan_code' => $plan->code,
                'status' => $status,
                'interval' => $price->interval,
                'currency' => $price->currency,
                'unit_amount' => $price->unit_amount,
                'trial_ends_at' => $trial ? $end : null,
                'billing_anchor_at' => $anchor,
                'current_period_start' => $now,
                'current_period_end' => $end,
            ])->save();

            $this->audit->recordAction('billing.subscription-started', $subscription, null, [
                'plan' => $plan->code, 'status' => $status->value, 'interval' => $price->interval->value,
                'unit_amount_minor' => $price->unit_amount->amountMinor, 'currency' => $price->currency,
            ], $tenant->getKey());
            $this->recordAssignment($assignedBy, $subscription, 'subscribe', $price);
            $this->limits->refresh((int) $tenant->getKey());

            $invoiceId = null;

            if ($status === SubscriptionStatus::Incomplete) {
                $invoiceId = $this->invoices->issue(
                    $subscription, InvoiceReason::Create, $price, $now, $end,
                    [new LineSpec('subscription', $this->describe($plan->name, $price), $price->unit_amount, 1, $now, $end)],
                )->id;
            } else {
                SubscriptionStarted::dispatch($subscription->id, (int) $tenant->getKey(), $plan->code, $status->value);
            }

            return [$subscription->id, $invoiceId];
        });

        if ($invoiceId !== null) {
            // La suscripción y su factura ya están confirmadas: un rechazo deja `incomplete` y el cliente puede reintentar.
            // Asignado por la plataforma (`$assignedBy`): el titular de la tarjeta NO está delante, así que es un cobro
            // off_session (sin 3DS ni `client_secret` para el admin); con 3DS obligatorio se rechaza (`authentication_required`).
            $this->payments->throwUnlessPaid($this->payments->collect($invoiceId, customerPresent: $assignedBy === null), $invoiceId);
        }

        return Subscription::query()->with('plan')->findOrFail($subscriptionId);
    }

    public function previewChange(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval): array
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.read');

        $subscription = $this->liveOrFail();
        $target = $this->targetPrice($subscription, $planCode, $currency, $interval);
        $this->assertSelfService($target, $subscription);
        $mode = $this->modeFor($subscription, $target);

        $preview = ['mode' => $mode, 'plan' => $target->plan->code, 'currency' => $target->currency, 'interval' => $target->interval->value, 'unit_amount' => $target->unit_amount, 'effective_at' => $mode === 'scheduled' ? $subscription->current_period_end->toIso8601String() : now()->toIso8601String()];

        if ($mode === 'immediate') {
            $proration = Proration::calculate($subscription->unit_amount, $target->unit_amount, $subscription->current_period_start, $subscription->current_period_end, now());
            $preview['proration'] = ['credit' => $proration->credit, 'charge' => $proration->charge, 'net' => $proration->net];
        }

        return $preview;
    }

    public function changePlan(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval): Subscription
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        return $this->change($planCode, $currency, $interval, null);
    }

    /**
     * Cambio de plan (tenant o plataforma, con el tenant en el contexto). `$assignedBy` = lo hace la plataforma:
     * admite planes privados y se audita (`billing.plan-assigned`, con motivo).
     *
     * @param  array{actor_id: int, reason: string}|null  $assignedBy
     */
    private function change(string $planCode, ?string $currency, BillingInterval $interval, ?array $assignedBy): Subscription
    {
        $price = $this->catalog->findPrice($planCode, $currency ?? $this->currentCurrency(), $interval);

        $invoiceId = DB::transaction(function () use ($price, $assignedBy): ?int {
            $subscription = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->lockForUpdate()->first()
                ?? throw BillingException::noSubscription();

            if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                throw BillingException::notActive();
            }

            if (Invoice::query()->where('subscription_id', $subscription->id)->where('billing_reason', InvoiceReason::Update->value)->where('status', InvoiceStatus::Open->value)->exists()) {
                throw BillingException::changeInProgress();
            }

            if ($assignedBy === null) {
                $this->assertSelfService($price, $subscription);
            }

            if ($price->id === $subscription->plan_price_id) {
                if ($subscription->scheduled_plan_price_id === null) {
                    throw BillingException::samePlan();
                }

                $subscription->scheduled_plan_price_id = null;   // volver al plan actual cancela la bajada programada
                $subscription->save();
                $this->audit->recordAction('billing.scheduled-change-canceled', $subscription, null, [], $subscription->tenant_id);
                $this->recordAssignment($assignedBy, $subscription, 'change:keep-current', $price);

                return null;
            }

            if ($price->id === $subscription->scheduled_plan_price_id) {
                throw BillingException::samePlan();
            }

            $mode = $this->modeFor($subscription, $price);
            $this->recordAssignment($assignedBy, $subscription, 'change:'.$mode, $price);

            if ($mode === 'trial') {
                $this->lifecycle->applyPrice($subscription, $price);   // en prueba no se cobra nada: cambia el precio que se cobrará al terminar

                return null;
            }

            if ($mode === 'scheduled') {
                $subscription->scheduled_plan_price_id = $price->id;
                $subscription->save();
                $this->audit->recordAction('billing.change-scheduled', $subscription, null, ['to_plan' => $price->plan->code, 'effective_at' => $subscription->current_period_end->toIso8601String()], $subscription->tenant_id);

                return null;
            }

            $now = CarbonImmutable::now();
            $proration = Proration::calculate($subscription->unit_amount, $price->unit_amount, $subscription->current_period_start, $subscription->current_period_end, $now);

            if ($proration->net->isZero()) {
                $this->lifecycle->applyPrice($subscription, $price);

                return null;
            }

            $invoice = $this->invoices->issue(
                $subscription, InvoiceReason::Update, $price, $now, $subscription->current_period_end,
                [
                    new LineSpec('proration_credit', 'Crédito por el tiempo no usado de '.$subscription->plan_code, $proration->credit->negate(), 1, $now, $subscription->current_period_end),
                    new LineSpec('proration_charge', 'Tiempo restante de '.$price->plan->name, $proration->charge, 1, $now, $subscription->current_period_end),
                ],
                [
                    'from_plan_price_id' => $subscription->plan_price_id,
                    'to_plan_price_id' => $price->id,
                    'period_start' => $subscription->current_period_start->toIso8601String(),
                    'ratio' => $proration->ratio,
                ],
            );

            return $invoice->id;
        });

        if ($invoiceId !== null) {
            $this->payments->throwUnlessPaid($this->payments->collect($invoiceId, customerPresent: $assignedBy === null), $invoiceId);
        }

        return Subscription::query()->with('plan')->whereIn('status', SubscriptionStatus::liveValues())->firstOrFail();
    }

    public function cancel(Tenant $tenant, User $actor, bool $immediately): Subscription
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        return DB::transaction(function () use ($immediately): Subscription {
            $subscription = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->lockForUpdate()->first()
                ?? throw BillingException::noSubscription();

            if ($immediately) {
                $subscription->canceled_at ??= now();
                $this->closeOpenInvoices($subscription);
                $this->lifecycle->transition($subscription, SubscriptionStatus::Canceled, 'user');

                return $subscription;
            }

            if ($subscription->status === SubscriptionStatus::Incomplete) {
                // Sin periodo pagado que respetar: la baja es inmediata.
                $subscription->canceled_at ??= now();
                $this->closeOpenInvoices($subscription);
                $this->lifecycle->transition($subscription, SubscriptionStatus::Canceled, 'user');

                return $subscription;
            }

            if (! $subscription->cancel_at_period_end) {
                $subscription->forceFill(['cancel_at_period_end' => true, 'canceled_at' => now()])->save();
                $this->audit->recordAction('billing.cancel-scheduled', $subscription, null, ['effective_at' => $subscription->current_period_end->toIso8601String()], $subscription->tenant_id);
            }

            return $subscription;
        });
    }

    public function resume(Tenant $tenant, User $actor): Subscription
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        return DB::transaction(function (): Subscription {
            $subscription = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->lockForUpdate()->first()
                ?? throw BillingException::noSubscription();

            if (! $subscription->cancel_at_period_end || ! $subscription->status->grantsAccess() || $subscription->current_period_end->isPast()) {
                throw BillingException::notResumable();
            }

            $subscription->forceFill(['cancel_at_period_end' => false, 'canceled_at' => null])->save();
            $this->audit->recordAction('billing.subscription-resumed', $subscription, null, [], $subscription->tenant_id);

            return $subscription;
        });
    }

    public function grantFreePeriod(int $tenantId, User $actor, int $days, string $reason): Subscription
    {
        $reason = $this->audit->requireReason('billing.free-period', $reason);

        if ($days < 1 || $days > (int) config('billing.free_period_max_days', 365)) {
            throw BillingException::invalidDays();
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        return $this->tenants->run($tenant, fn () => DB::transaction(function () use ($days, $reason, $actor): Subscription {
            $subscription = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->lockForUpdate()->first()
                ?? throw BillingException::noSubscription();

            if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                throw BillingException::notActive();
            }

            $before = $subscription->current_period_end;
            $end = CarbonImmutable::instance($before)->addDays($days);

            $subscription->current_period_end = $end;
            $subscription->billing_anchor_at = $end;   // el cobro se desplaza: la nueva fecha pasa a ser el ancla
            if ($subscription->status === SubscriptionStatus::Trialing) {
                $subscription->trial_ends_at = $end;
            }
            $subscription->save();

            $this->audit->recordAction('billing.free-period', $subscription, $reason, [
                'days' => $days, 'period_end_before' => $before->toIso8601String(), 'period_end_after' => $end->toIso8601String(), 'actor_id' => $actor->getKey(),
            ], $subscription->tenant_id);

            return $subscription;
        }));
    }

    /**
     * Un tenant no contrata por sí mismo un plan privado (`is_public = false`, acuerdos a medida): solo la plataforma lo
     * asigna (`assignPlan`). Seguir en el precio privado que ya tiene sí está permitido.
     */
    private function assertSelfService(PlanPrice $price, ?Subscription $current): void
    {
        if (! $price->plan->is_public && $price->id !== $current?->plan_price_id) {
            throw BillingException::planPrivate();
        }
    }

    /**
     * @param  array{actor_id: int, reason: string}|null  $assignedBy
     */
    private function recordAssignment(?array $assignedBy, Subscription $subscription, string $mode, PlanPrice $price): void
    {
        if ($assignedBy === null) {
            return;
        }

        $this->audit->recordAction('billing.plan-assigned', $subscription, $assignedBy['reason'], [
            'mode' => $mode, 'plan' => $price->plan->code, 'plan_price_id' => $price->id, 'interval' => $price->interval->value,
            'unit_amount_minor' => $price->unit_amount->amountMinor, 'currency' => $price->currency, 'actor_id' => $assignedBy['actor_id'],
        ], $subscription->tenant_id);
    }

    /** `trial` (sin cobro), `immediate` (subida: misma moneda e intervalo y más cara) o `scheduled` (el resto, al final del periodo). */
    private function modeFor(Subscription $subscription, PlanPrice $target): string
    {
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return 'trial';
        }

        $upgrade = $target->currency === $subscription->currency
            && $target->interval === $subscription->interval
            && $target->unit_amount->greaterThan($subscription->unit_amount);

        return $upgrade ? 'immediate' : 'scheduled';
    }

    private function targetPrice(Subscription $subscription, string $planCode, ?string $currency, BillingInterval $interval): PlanPrice
    {
        return $this->catalog->findPrice($planCode, $currency ?? $subscription->currency, $interval);
    }

    private function liveOrFail(): Subscription
    {
        $subscription = Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->first() ?? throw BillingException::noSubscription();

        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw BillingException::notActive();
        }

        return $subscription;
    }

    private function currentCurrency(): string
    {
        return (string) (Subscription::query()->whereIn('status', SubscriptionStatus::liveValues())->value('currency') ?? config('billing.default_currency', 'USD'));
    }

    /** Facturas abiertas de una suscripción que se da de baja: la del alta se anula; la de una renovación impagada queda incobrable. */
    private function closeOpenInvoices(Subscription $subscription): void
    {
        foreach (Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->lockForUpdate()->get() as $invoice) {
            if ($invoice->billing_reason === InvoiceReason::Cycle) {
                $invoice->status = InvoiceStatus::Uncollectible;
            } else {
                $invoice->status = InvoiceStatus::Void;
                $invoice->voided_at = now();
            }
            $invoice->save();
        }
    }

    private function describe(string $planName, PlanPrice $price): string
    {
        return $planName.' ('.($price->interval === BillingInterval::Year ? 'anual' : 'mensual').')';
    }
}

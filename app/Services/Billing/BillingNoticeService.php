<?php

namespace App\Services\Billing;

use App\Contracts\BillingNoticeServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\InvoiceReason;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class BillingNoticeService implements BillingNoticeServiceInterface
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly TenantContext $tenants,
    ) {}

    public function invoicePaid(int $tenantId, int $invoiceId): bool
    {
        return $this->guarded($tenantId, function (Tenant $tenant) use ($invoiceId): bool {
            $invoice = Invoice::query()->find($invoiceId);

            if (! $invoice instanceof Invoice || $invoice->total->amountMinor <= 0) {
                return false;
            }

            return $this->send($tenant, $this->subscription($invoice->subscription_id), 'billing_invoice_paid', "paid:inv{$invoice->id}", [
                'number' => (string) $invoice->number,
                'amount' => $invoice->total->format(app()->getLocale()),
                'action_url' => $this->url('invoice_url', ['id' => $invoice->id]),
            ]);
        });
    }

    public function paymentFailed(int $tenantId, int $invoiceId, int $attempt, ?string $nextRetryAt): bool
    {
        return $this->guarded($tenantId, function (Tenant $tenant) use ($invoiceId, $attempt, $nextRetryAt): bool {
            $invoice = Invoice::query()->find($invoiceId);

            // Solo la morosidad de una renovación: el primer pago y los ajustes ya devuelven el error al cliente en la petición.
            // Con reintentos agotados (`nextRetryAt` null) avisa `subscriptionEnded`.
            if (! $invoice instanceof Invoice || $invoice->billing_reason !== InvoiceReason::Cycle || $nextRetryAt === null) {
                return false;
            }

            $subscription = $this->subscription($invoice->subscription_id);
            $last = $subscription->renewal_attempts >= count((array) config('billing.dunning.retry_days', []));
            $params = [
                'number' => (string) $invoice->number,
                'amount' => $invoice->total->format(app()->getLocale()),
                'retry_date' => substr($nextRetryAt, 0, 10),
                'action_url' => $this->url('pay_url', ['id' => $invoice->id]),
            ];

            return $last
                ? $this->send($tenant, $subscription, 'billing_final_warning', "final:inv{$invoice->id}", $params)
                : $this->send($tenant, $subscription, 'billing_payment_failed', "failed:inv{$invoice->id}:a{$attempt}", $params);
        });
    }

    public function subscriptionEnded(int $tenantId, int $subscriptionId, string $status, string $reason): bool
    {
        return $this->guarded($tenantId, function (Tenant $tenant) use ($subscriptionId, $status): bool {
            $subscription = $this->subscription($subscriptionId);

            return $this->send($tenant, $subscription, $status === 'expired' ? 'billing_subscription_expired' : 'billing_subscription_canceled', "ended:{$status}", [
                'plan' => $subscription->plan->name,
                'action_url' => $this->url('billing_url'),
            ]);
        });
    }

    public function sendUpcoming(): array
    {
        $counts = ['billing_trial_ending' => 0, 'billing_renewal_upcoming' => 0];
        $now = now();
        $trialLimit = $now->copy()->addDays((int) config('billing.notices.trial_ending_days', 3));
        $renewalLimit = $now->copy()->addDays((int) config('billing.notices.renewal_upcoming_days', 3));

        $rows = TenantQuery::withoutTenant(fn () => DB::table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->whereNull('subscriptions.deleted_at')
            ->whereNull('tenants.deleted_at')
            ->where('tenants.status', 'active')
            ->where(function ($q) use ($now, $trialLimit, $renewalLimit): void {
                $q->where(fn ($q) => $q->where('subscriptions.status', SubscriptionStatus::Trialing->value)
                    ->whereNotNull('subscriptions.trial_ends_at')->where('subscriptions.trial_ends_at', '>', $now)->where('subscriptions.trial_ends_at', '<=', $trialLimit))
                    ->orWhere(fn ($q) => $q->where('subscriptions.status', SubscriptionStatus::Active->value)
                        ->where('subscriptions.cancel_at_period_end', false)->where('subscriptions.unit_amount_minor', '>', 0)
                        ->where('subscriptions.current_period_end', '>', $now)->where('subscriptions.current_period_end', '<=', $renewalLimit));
            })
            ->orderBy('subscriptions.id')
            ->limit((int) config('billing.notices.batch', 500))
            ->get(['subscriptions.id', 'subscriptions.tenant_id']), 'barrido de avisos de facturación (billing:notify)');

        foreach ($rows as $row) {
            $type = $this->guardedValue((int) $row->tenant_id, fn (Tenant $tenant): ?string => $this->upcoming($tenant, (int) $row->id));

            if ($type !== null) {
                $counts[$type]++;
            }
        }

        return $counts;
    }

    /** Avisa (si aplica y no se envió ya) de la prueba o renovación próxima de UNA suscripción; devuelve el tipo enviado. */
    private function upcoming(Tenant $tenant, int $subscriptionId): ?string
    {
        $subscription = $this->subscription($subscriptionId);
        $now = now();

        if ($subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null && $subscription->trial_ends_at->gt($now)) {
            $end = $subscription->trial_ends_at;
            $sent = $this->send($tenant, $subscription, 'billing_trial_ending', 'trial:'.$end->getTimestamp(), [
                'plan' => $subscription->plan->name,
                'days' => max(1, (int) ceil($now->diffInHours($end) / 24)),
                'date' => $end->toDateString(),
                'action_url' => $this->url('billing_url'),
            ]);

            return $sent ? 'billing_trial_ending' : null;
        }

        if ($subscription->status === SubscriptionStatus::Active && ! $subscription->cancel_at_period_end && $subscription->unit_amount->amountMinor > 0) {
            $end = $subscription->current_period_end;
            $sent = $this->send($tenant, $subscription, 'billing_renewal_upcoming', 'renewal:'.$end->getTimestamp(), [
                'plan' => $subscription->plan->name,
                'amount' => $subscription->unit_amount->format(app()->getLocale()),
                'date' => $end->toDateString(),
                'action_url' => $this->url('billing_url'),
            ]);

            return $sent ? 'billing_renewal_upcoming' : null;
        }

        return null;
    }

    /**
     * Reclama el aviso (UNIQUE) y lo encola en la MISMA transacción: si encolar falla, el reclamo se revierte y se puede reintentar.
     * Sin destinatarios no reclama nada (cuando haya dueño, saldrá).
     *
     * @param  array<string, scalar|null>  $params
     */
    private function send(Tenant $tenant, Subscription $subscription, string $type, string $key, array $params): bool
    {
        $recipients = $this->recipients($tenant);

        if ($recipients->isEmpty()) {
            return false;
        }

        return DB::transaction(function () use ($tenant, $subscription, $type, $key, $params, $recipients): bool {
            // `tenant_id` explícito y siempre el de la suscripción cargada (que ya salió del tenant del contexto).
            abort_unless((int) $subscription->tenant_id === (int) $tenant->getKey(), 500);

            $claimed = DB::table('billing_notices')->insertOrIgnore([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'type' => $type,
                'period_key' => $key,
                'recipients' => $recipients->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($claimed === 0) {
                return false;
            }

            $this->notifications->notify($recipients, $type, $params, $tenant);

            return true;
        });
    }

    /**
     * Dueños y administradores del tenant con membresía activa (no todos los miembros).
     *
     * @return Collection<int, User>
     */
    private function recipients(Tenant $tenant): Collection
    {
        $roles = array_map('strval', (array) config('billing.notices.recipient_roles', ['owner', 'admin']));

        return User::query()
            ->whereIn('id', DB::table('tenant_user')
                ->where('tenant_id', $tenant->getKey())->where('status', 'active')->whereIn('role', $roles)->select('user_id'))
            ->orderBy('id')
            ->get();
    }

    private function subscription(int $id): Subscription
    {
        return Subscription::query()->with('plan')->findOrFail($id);
    }

    /**
     * @param  array<string, scalar>  $vars
     */
    private function url(string $key, array $vars = []): string
    {
        $url = (string) config("billing.notices.{$key}", '');

        foreach ($vars as $name => $value) {
            $url = str_replace('{'.$name.'}', (string) $value, $url);
        }

        return $url;
    }

    /**
     * @param  callable(Tenant): bool  $callback
     */
    private function guarded(int $tenantId, callable $callback): bool
    {
        return $this->guardedValue($tenantId, $callback) === true;
    }

    /**
     * Ejecuta dentro del tenant; un fallo se registra y NO se propaga al flujo de cobro que disparó el evento.
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T|null
     */
    private function guardedValue(int $tenantId, callable $callback): mixed
    {
        try {
            $tenant = TenantQuery::withoutTenant(fn () => Tenant::query()->find($tenantId), 'avisos de facturación: abrir el tenant del evento');

            if (! $tenant instanceof Tenant) {
                return null;
            }

            return $this->tenants->run($tenant, fn () => $callback($tenant));
        } catch (Throwable $e) {
            LogService::error('No se pudo enviar un aviso de facturación: '.$e->getMessage(), ['tenant_id' => $tenantId]);

            return null;
        }
    }
}

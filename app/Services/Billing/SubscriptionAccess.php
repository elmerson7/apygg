<?php

namespace App\Services\Billing;

use App\Contracts\SubscriptionAccessInterface;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionBlockedException;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Política de bloqueo por estado de la suscripción (docs/billing.md §Bloqueo por estado). Sin estado entre peticiones
 * (Octane) y sin caché: una lectura indexada por tenant y petición, así que un cambio de estado se nota al instante.
 *
 * - `trialing` / `active`: todo.
 * - `past_due`: dentro de la gracia (`billing.enforcement.past_due_grace_days` desde que se emitió la factura impagada)
 *   todo con la cabecera `X-Subscription-Warning`; pasada la gracia, solo lectura (402 `payment_required`).
 * - `incomplete`: solo lectura (402 `payment_required`). `canceled` / `expired`: solo lectura (402 `subscription_inactive`).
 * - Sin ninguna suscripción: `billing.enforcement.no_subscription` = `allow` (plan gratuito) o `require` (solo lectura,
 *   402 `subscription_required`).
 *
 * Lectura = GET/HEAD/OPTIONS. Siempre pasa lo de `allow_paths` (facturación, perfil, derechos del usuario, salud).
 */
class SubscriptionAccess implements SubscriptionAccessInterface
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function stateFor(int $tenantId): array
    {
        $subscription = $this->tenants->withoutTenant(
            fn () => Subscription::query()->where('tenant_id', $tenantId)->orderByDesc('id')->first()
        );

        if (! $subscription instanceof Subscription) {
            $require = config('billing.enforcement.no_subscription') === 'require';

            return $this->state(
                $require ? self::MODE_READ_ONLY : self::MODE_FULL,
                null,
                $require ? 'subscription_required' : null,
                action: $require ? $this->action('choose_plan', 'POST', '/tenant/billing/subscription') : null,
            );
        }

        $status = $subscription->status;

        return match ($status) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => $this->state(self::MODE_FULL, $status->value),
            SubscriptionStatus::PastDue => $this->pastDue($subscription),
            SubscriptionStatus::Incomplete => $this->unpaid($status, $this->openInvoice($subscription)),
            SubscriptionStatus::Canceled, SubscriptionStatus::Expired => $this->state(
                self::MODE_READ_ONLY,
                $status->value,
                'subscription_inactive',
                action: $this->action('resubscribe', 'POST', '/tenant/billing/subscription'),
            ),
        };
    }

    public function enforce(Request $request, Tenant $tenant): ?string
    {
        if (! config('billing.enforcement.enabled', true)) {
            return null;
        }

        $state = $this->stateFor((int) $tenant->getKey());

        if ($state['mode'] === self::MODE_FULL) {
            return null;
        }

        if ($state['mode'] === self::MODE_GRACE) {
            return 'past_due; grace_ends_at='.$state['grace_ends_at'];
        }

        if (in_array(strtoupper($request->method()), self::READ_METHODS, true) || $request->is(...$this->allowPaths())) {
            return null;
        }

        throw new SubscriptionBlockedException(
            match ($state['code']) {
                'payment_required' => 'Hay un pago pendiente: la empresa está en solo lectura hasta regularizarlo.',
                'subscription_required' => 'La empresa necesita una suscripción para modificar datos.',
                default => 'La suscripción no está activa: la empresa está en solo lectura.',
            },
            (string) $state['code'],
            [
                'subscription_status' => $state['status'],
                'access_mode' => $state['mode'],
                'grace_ends_at' => $state['grace_ends_at'],
                'invoice_id' => $state['invoice_id'],
                'suggested_action' => $state['suggested_action'],
            ],
        );
    }

    /**
     * @return array{mode: string, status: string|null, code: string|null, grace_ends_at: string|null, invoice_id: int|null, suggested_action: array{action: string, method: string, path: string}|null}
     */
    private function pastDue(Subscription $subscription): array
    {
        $invoice = $this->openInvoice($subscription);
        $start = $subscription->current_period_start;

        if ($invoice instanceof Invoice && $invoice->issued_at !== null) {
            $start = $invoice->issued_at;
        }

        $graceEnds = $start->copy()->addDays(max(0, (int) config('billing.enforcement.past_due_grace_days', 7)));

        if (now()->lt($graceEnds)) {
            return $this->state(self::MODE_GRACE, 'past_due', null, $graceEnds->toIso8601String(), $invoice instanceof Invoice ? (int) $invoice->getKey() : null);
        }

        return $this->unpaid(SubscriptionStatus::PastDue, $invoice, $graceEnds->toIso8601String());
    }

    /**
     * @return array{mode: string, status: string|null, code: string|null, grace_ends_at: string|null, invoice_id: int|null, suggested_action: array{action: string, method: string, path: string}|null}
     */
    private function unpaid(SubscriptionStatus $status, ?Invoice $invoice, ?string $graceEnds = null): array
    {
        return $this->state(
            self::MODE_READ_ONLY,
            $status->value,
            'payment_required',
            $graceEnds,
            $invoice instanceof Invoice ? (int) $invoice->getKey() : null,
            $this->action('pay_invoice', 'POST', ! $invoice instanceof Invoice ? '/tenant/billing/invoices' : "/tenant/billing/invoices/{$invoice->getKey()}/pay"),
        );
    }

    /** La factura impagada más antigua de la suscripción (la que empezó la morosidad). */
    private function openInvoice(Subscription $subscription): ?Invoice
    {
        return $this->tenants->withoutTenant(fn () => Invoice::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)
            ->orderBy('id')
            ->first());
    }

    /**
     * @return list<string>
     */
    private function allowPaths(): array
    {
        return array_values(array_map('strval', (array) config('billing.enforcement.allow_paths', [])));
    }

    /**
     * @return array{action: string, method: string, path: string}
     */
    private function action(string $action, string $method, string $path): array
    {
        return ['action' => $action, 'method' => $method, 'path' => $path];
    }

    /**
     * @param  array{action: string, method: string, path: string}|null  $action
     * @return array{mode: string, status: string|null, code: string|null, grace_ends_at: string|null, invoice_id: int|null, suggested_action: array{action: string, method: string, path: string}|null}
     */
    private function state(string $mode, ?string $status, ?string $code = null, ?string $graceEndsAt = null, ?int $invoiceId = null, ?array $action = null): array
    {
        return ['mode' => $mode, 'status' => $status, 'code' => $code, 'grace_ends_at' => $graceEndsAt, 'invoice_id' => $invoiceId, 'suggested_action' => $action];
    }
}

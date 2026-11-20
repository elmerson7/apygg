<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeResult;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\LogService;
use App\Support\Billing\PaymentOutcome;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conciliación con la pasarela. Tres reglas:
 *
 * 1. Un cobro de resultado incierto se consulta POR CLAVE/ID antes de reenviarse (las claves de Stripe caducan a las 24 h).
 * 2. Todo estado de la pasarela entra por `PaymentProcessorInterface::applyGatewayResult` (mismo camino que el síncrono).
 * 3. Lo que no se puede aplicar solo (cobro sin reflejo, duplicado, importe distinto, reembolso o disputa externos) se
 *    registra en `payment_discrepancies` y alerta; nunca se «arregla» a ciegas.
 */
class PaymentReconciler implements PaymentReconcilerInterface
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentProcessorInterface $processor,
        private readonly PaymentDiscrepancyServiceInterface $discrepancies,
        private readonly AuditServiceInterface $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function applyCharge(ChargeResult $charge): string
    {
        $gateway = $this->gateway->name();
        $payment = Payment::query()->where('gateway', $gateway)->where('gateway_payment_id', $charge->paymentId)->first()
            ?? ($charge->idempotencyKey !== null ? Payment::query()->where('gateway', $gateway)->where('idempotency_key', $charge->idempotencyKey)->first() : null);

        if (! $payment instanceof Payment) {
            if ($charge->succeeded) {
                // Dinero cobrado en la pasarela sin ningún intento local que lo explique.
                $this->discrepancies->record('succeeded_without_local', $gateway, $charge->paymentId, null, $charge->amountMinor, $charge->currency, ['idempotency_key' => $charge->idempotencyKey]);

                return 'discrepancy';
            }

            return 'ignored';
        }

        $mismatch = $this->mismatch($payment, $charge);

        if ($mismatch !== null) {
            $this->discrepancies->record('data_mismatch', $gateway, $charge->paymentId, $payment, $charge->amountMinor, $charge->currency, $mismatch);

            return 'discrepancy';
        }

        if ($payment->gateway_payment_id !== null && $payment->gateway_payment_id !== $charge->paymentId) {
            // Otro PaymentIntent para el mismo intento local: si cobró, es un doble cobro.
            if ($charge->succeeded) {
                $this->discrepancies->record('duplicate_charge', $gateway, $charge->paymentId, $payment, $charge->amountMinor, $charge->currency, ['kept' => $payment->gateway_payment_id]);

                return 'discrepancy';
            }

            return 'ignored';
        }

        return match ($payment->status) {
            PaymentStatus::Pending => $this->apply($payment, $charge),
            PaymentStatus::RequiresAction => $this->apply($payment, $this->closeBeforeFailing($charge)),
            PaymentStatus::Succeeded => $charge->succeeded ? 'already_applied' : 'ignored',
            PaymentStatus::Failed => $charge->succeeded ? $this->succeededAfterFailure($payment, $charge) : 'already_applied',
            PaymentStatus::Refunded => 'ignored',
        };
    }

    public function applyRefund(string $gatewayPaymentId, ?string $idempotencyKey, int $refundedMinor, ?string $refundId): string
    {
        $payment = $this->reflected($gatewayPaymentId, $idempotencyKey);

        if ($this->isOtherIntent($payment, $gatewayPaymentId)) {
            $this->discrepancies->record('refunded_externally', $this->gateway->name(), "{$gatewayPaymentId}:{$refundedMinor}", $payment, $refundedMinor, $payment->currency, ['refund_id' => $refundId, 'payment_intent' => $gatewayPaymentId, 'kept' => $payment->gateway_payment_id]);

            return 'discrepancy';
        }

        return DB::transaction(function () use ($payment, $refundedMinor, $refundId): string {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $total = min($refundedMinor, $payment->amount->amountMinor);

            if ($total <= $payment->refunded_amount_minor) {
                return 'already_applied';   // evento repetido o más viejo que lo ya registrado
            }

            $invoiceVoid = Invoice::query()->whereKey($payment->invoice_id)->where('status', InvoiceStatus::Void->value)->exists();
            $attributes = ['refunded_amount_minor' => $total];

            if ($total >= $payment->amount->amountMinor && $payment->status === PaymentStatus::Succeeded) {
                $attributes += ['status' => PaymentStatus::Refunded, 'refunded_at' => now(), 'refund_id' => $refundId];
            }

            $wasOurs = $payment->status === PaymentStatus::Refunded;   // `PaymentProcessor::refund` ya lo registró
            $payment->forceFill($attributes)->save();

            if ($wasOurs || $invoiceVoid) {
                return 'applied';   // la devolución que hicimos nosotros por una factura anulada
            }

            $this->audit->recordAction('billing.payment-refunded-externally', $payment, null, ['refund_id' => $refundId, 'refunded_minor' => $total, 'invoice_id' => $payment->invoice_id], $payment->tenant_id);
            // La factura sigue pagada y la suscripción no se toca: lo decide una persona.
            $this->discrepancies->record('refunded_externally', $this->gateway->name(), "{$payment->gateway_payment_id}:{$total}", $payment, $total, $payment->currency, ['refund_id' => $refundId, 'full' => $total >= $payment->amount->amountMinor]);

            return 'discrepancy';
        });
    }

    public function recordDispute(string $gatewayPaymentId, ?string $idempotencyKey, string $disputeId, ?string $reason): string
    {
        $payment = $this->reflected($gatewayPaymentId, $idempotencyKey);

        if ($this->isOtherIntent($payment, $gatewayPaymentId)) {
            $this->discrepancies->record('dispute', $this->gateway->name(), $disputeId, $payment, null, $payment->currency, ['reason' => $reason, 'payment_intent' => $gatewayPaymentId, 'kept' => $payment->gateway_payment_id]);

            return 'discrepancy';
        }

        return DB::transaction(function () use ($payment, $disputeId, $reason): string {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->dispute_id !== null) {
                return 'already_applied';
            }

            $payment->forceFill(['dispute_id' => $disputeId, 'disputed_at' => now()])->save();
            $this->audit->recordAction('billing.payment-disputed', $payment, null, ['dispute_id' => $disputeId, 'reason' => $reason, 'invoice_id' => $payment->invoice_id], $payment->tenant_id);
            $this->discrepancies->record('dispute', $this->gateway->name(), $disputeId, $payment, $payment->amount->amountMinor, $payment->currency, ['reason' => $reason]);

            return 'applied';
        });
    }

    public function reconcile(): array
    {
        if ($this->gateway->name() === 'null') {
            return ['skipped_no_gateway' => 1];
        }

        $counts = [];
        $batch = (int) config('billing.reconcile.batch', 200);

        $stale = TenantQuery::withoutTenant(fn () => DB::table('payments')
            ->where('gateway', $this->gateway->name())->where('status', PaymentStatus::Pending->value)->whereNull('deleted_at')
            ->where('claimed_until', '<=', now()->subMinutes((int) config('billing.reconcile.stale_minutes', 10)))
            ->orderBy('id')->limit($batch)->get(['id', 'tenant_id']), 'conciliación: cobros pendientes con resultado incierto');

        foreach ($stale as $row) {
            $this->count($counts, 'stale_'.$this->inTenant((int) $row->tenant_id, fn () => $this->resolveStale((int) $row->id)));
        }

        $awaiting = TenantQuery::withoutTenant(fn () => DB::table('payments')
            ->where('payments.gateway', $this->gateway->name())->where('payments.status', PaymentStatus::RequiresAction->value)->whereNull('payments.deleted_at')
            ->where(fn ($q) => $q->where('payments.claimed_until', '<=', now())
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->where('invoices.status', '!=', InvoiceStatus::Open->value)))
            ->orderBy('payments.id')->limit($batch)->get(['payments.id', 'payments.tenant_id']), 'conciliación: cobros 3DS vencidos');

        foreach ($awaiting as $row) {
            $this->count($counts, 'action_'.$this->inTenant((int) $row->tenant_id, fn () => $this->expireAction((int) $row->id)));
        }

        $this->reconcileRecent($counts);

        ksort($counts);

        return $counts;
    }

    /**
     * Cobros exitosos de la pasarela de las últimas horas contra lo local: lo que falte o no cuadre se aplica o se registra.
     *
     * @param  array<string, int>  $counts
     */
    private function reconcileRecent(array &$counts): void
    {
        $since = now()->subHours((int) config('billing.reconcile.lookback_hours', 48))->getTimestamp();
        $cursor = null;
        $maxPages = (int) config('billing.reconcile.max_pages', 20);

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $list = $this->gateway->recentCharges($since, $cursor, 100);
            } catch (IntegrationException $e) {
                LogService::warning('billing: la conciliación no pudo listar los cobros de la pasarela', ['error' => $e::class], 'security');
                $this->count($counts, 'list_failed');

                return;
            }

            foreach ($list['items'] as $charge) {
                if (! $charge->succeeded || $charge->idempotencyKey === null) {
                    continue;   // solo interesan los cobros nuestros (llevan nuestra clave) que SÍ cobraron
                }

                $tenantId = $this->tenantOf($charge);

                if ($tenantId === null) {
                    $this->count($counts, 'foreign_charge');

                    continue;
                }

                $this->count($counts, 'recent_'.$this->inTenant($tenantId, fn () => $this->applyCharge($charge)));
            }

            $cursor = $list['next'];

            if ($cursor === null) {
                return;
            }
        }

        // Quedaron cobros por revisar: no se corta en silencio (los más antiguos de la ventana no se cuadraron esta vez).
        $this->count($counts, 'list_truncated');
        LogService::error('billing: la conciliación se detuvo en max_pages con cobros sin revisar; sube billing.reconcile.max_pages o reduce lookback_hours', ['max_pages' => $maxPages], 'security');
    }

    private function resolveStale(int $paymentId): string
    {
        $payment = Payment::query()->find($paymentId);

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Pending) {
            return 'skipped';
        }

        try {
            $charge = $payment->gateway_payment_id !== null
                ? $this->gateway->retrieve($payment->gateway_payment_id)
                : $this->gateway->findCharge(BillingAccount::query()->first()?->gateway_customer_id, $payment->idempotency_key, $payment->created_at->subHour()->getTimestamp());
        } catch (IntegrationException $e) {
            LogService::warning('billing: no se pudo consultar un cobro incierto', ['payment_id' => $payment->id, 'error' => $e::class], 'security');

            return 'unresolved';
        }

        // Sin cobro en la pasarela: `billing:renew` lo retoma con la misma clave (seguro, ya está confirmado que no existe).
        return $charge === null ? 'not_found' : $this->applyCharge($charge);
    }

    private function expireAction(int $paymentId): string
    {
        $payment = Payment::query()->find($paymentId);

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::RequiresAction || $payment->gateway_payment_id === null) {
            return 'skipped';
        }

        try {
            $charge = $this->gateway->cancelCharge($payment->gateway_payment_id);
        } catch (IntegrationException $e) {
            LogService::warning('billing: no se pudo cancelar un cobro 3DS vencido', ['payment_id' => $payment->id, 'error' => $e::class], 'security');

            return 'unresolved';
        }

        if (! $charge->succeeded && ! $charge->requiresAction && ! $charge->processing) {
            $charge = new ChargeResult(false, $charge->paymentId, 'action_expired', 'El cliente no confirmó el pago a tiempo', customerId: $charge->customerId, amountMinor: $charge->amountMinor, currency: $charge->currency, idempotencyKey: $charge->idempotencyKey, metadata: $charge->metadata);
        }

        return $this->applyCharge($charge);
    }

    /**
     * 3DS que falla (`payment_intent.payment_failed`): el PaymentIntent vuelve a `requires_payment_method` y, como el front tiene su
     * `client_secret`, puede volver a confirmarlo (con otra tarjeta) aunque aquí ya conste como fallido y se haya abierto otro
     * intento (reintento de morosidad o «pagar ahora»): DOBLE COBRO. Antes de darlo por fallido se cancela en la pasarela; si
     * la cancelación no es posible porque ya se cobró, se aplica el cobro. Si la pasarela no responde, se lanza el error: el
     * intento sigue `requires_action` (nada se abre encima) y el webhook se reintenta (o lo cancela la conciliación al vencer).
     *
     * @throws IntegrationException
     */
    private function closeBeforeFailing(ChargeResult $charge): ChargeResult
    {
        if ($charge->succeeded || $charge->requiresAction || $charge->processing || $charge->paymentId === ''
            || in_array($charge->failureCode, ['canceled', 'action_expired'], true)) {
            return $charge;
        }

        $current = $this->gateway->cancelCharge($charge->paymentId);

        return $current->succeeded || $current->processing ? $current : $charge;
    }

    private function apply(Payment $payment, ChargeResult $charge): string
    {
        return match ($this->processor->applyGatewayResult($payment->id, $charge)) {
            PaymentOutcome::Paid => 'applied',
            PaymentOutcome::Declined => 'declined',
            PaymentOutcome::Voided => 'voided',
            PaymentOutcome::Pending, PaymentOutcome::RequiresAction, PaymentOutcome::InProgress => 'pending',
            PaymentOutcome::OtherIntent => 'discrepancy',   // ya registrada por `settle` (bajo bloqueo)
            PaymentOutcome::Skipped => 'ignored',
        };
    }

    private function succeededAfterFailure(Payment $payment, ChargeResult $charge): string
    {
        // El libro local dice «fallido» y la pasarela cobró: no se reescribe a ciegas (la suscripción pudo seguir su camino).
        $this->discrepancies->record('succeeded_after_failure', $this->gateway->name(), $charge->paymentId, $payment, $charge->amountMinor, $charge->currency, ['local_status' => $payment->status->value, 'failure_code' => $payment->failure_code]);

        return 'discrepancy';
    }

    /**
     * @return array<string, mixed>|null qué no cuadra (importe, moneda o cliente), o null si todo coincide
     */
    private function mismatch(Payment $payment, ChargeResult $charge): ?array
    {
        $problems = [];

        if ($charge->amountMinor !== null && $charge->amountMinor !== $payment->amount->amountMinor) {
            $problems['amount'] = ['local' => $payment->amount->amountMinor, 'gateway' => $charge->amountMinor];
        }

        if ($charge->currency !== null && strtoupper($charge->currency) !== strtoupper($payment->currency)) {
            $problems['currency'] = ['local' => $payment->currency, 'gateway' => $charge->currency];
        }

        $customer = BillingAccount::query()->first()?->gateway_customer_id;

        if ($charge->customerId !== null && $customer !== null && $charge->customerId !== $customer) {
            $problems['customer'] = true;
        }

        return $problems === [] ? null : $problems;
    }

    /**
     * Un reembolso o una disputa pueden llegar antes que el éxito del cobro: se reintenta (el job de webhooks tiene backoff).
     */
    private function reflected(string $gatewayPaymentId, ?string $idempotencyKey): Payment
    {
        $payment = Payment::query()->where('gateway', $this->gateway->name())->where('gateway_payment_id', $gatewayPaymentId)->first()
            ?? ($idempotencyKey !== null ? Payment::query()->where('gateway', $this->gateway->name())->where('idempotency_key', $idempotencyKey)->first() : null);

        if ($payment instanceof Payment && $this->isOtherIntent($payment, $gatewayPaymentId)) {
            return $payment;   // el llamante lo trata como discrepancia sin tocar el pago
        }

        // La empresa se resolvió por datos locales, así que el cobro existe o está a punto de reflejarse: se reintenta.
        if (! $payment instanceof Payment || in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
            throw new TransientIntegrationError('El cobro aún no está reflejado localmente; se reintentará', 'payments');
        }

        return $payment;
    }

    /**
     * Misma clave de idempotencia pero OTRO PaymentIntent que el que registra el pago (p. ej. el duplicado de una
     * `duplicate_charge`, que alguien devolvió o le disputaron en Stripe): no es este cobro. Aplicarle el evento marcaría como
     * devuelto o disputado un cobro que sigue intacto.
     */
    private function isOtherIntent(Payment $payment, string $gatewayPaymentId): bool
    {
        return $payment->gateway_payment_id !== null && $payment->gateway_payment_id !== $gatewayPaymentId;
    }

    private function tenantOf(ChargeResult $charge): ?int
    {
        return TenantQuery::withoutTenant(function () use ($charge): ?int {
            $id = DB::table('payments')->where('gateway', $this->gateway->name())->where('idempotency_key', $charge->idempotencyKey)->value('tenant_id');

            if ($id !== null) {
                return (int) $id;
            }

            // Sin intento local: la metadata la puso APYGG, pero solo se acepta si el cliente de la pasarela es el de esa empresa.
            $claimed = $charge->metadata['tenant_id'] ?? null;

            if ($claimed === null || ! ctype_digit($claimed) || $charge->customerId === null) {
                return null;
            }

            $ok = DB::table('billing_accounts')->where('tenant_id', (int) $claimed)->where('gateway', $this->gateway->name())->where('gateway_customer_id', $charge->customerId)->exists();

            return $ok ? (int) $claimed : null;
        }, 'conciliación: empresa de un cobro de la pasarela');
    }

    /**
     * @param  callable(): string  $work
     */
    private function inTenant(int $tenantId, callable $work): string
    {
        try {
            $tenant = Tenant::query()->find($tenantId);

            return $tenant instanceof Tenant ? (string) $this->tenants->run($tenant, $work(...)) : 'tenant_missing';
        } catch (Throwable $e) {
            LogService::error('billing:reconcile falló para un cobro', ['tenant_id' => $tenantId, 'error' => $e::class.': '.$e->getMessage()], 'security');

            return 'error';
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function count(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
}

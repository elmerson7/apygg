<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentFailed;
use App\Exceptions\BillingException;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeRequest;
use App\Integrations\Payments\ChargeResult;
use App\Integrations\Payments\RefundRequest;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogService;
use App\Support\Billing\PaymentOutcome;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantQuery;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cobro de facturas por la pasarela, sin doble cobro posible:
 *
 * 1. RESERVA (transacción): con la suscripción y la factura bloqueadas se inserta la fila `payments` (`pending`,
 *    clave `inv-<id>-a<intento>`) con un arrendamiento. Si otro proceso ya tiene uno vigente, no se cobra.
 * 2. COBRO: llamada a la pasarela fuera de toda transacción (no se mantienen bloqueos durante la red), con la clave de idempotencia.
 * 3. REGISTRO (transacción): se vuelve a bloquear en el mismo orden y se pasa el pago a `succeeded`/`failed`.
 *
 * Si el proceso muere entre 2 y 3, el pago queda `pending`; al vencer el arrendamiento se retoma con LA MISMA
 * clave, y la pasarela devuelve el resultado original en vez de cobrar otra vez.
 * Orden de bloqueos: suscripción -> factura -> pago.
 */
class PaymentProcessor implements PaymentProcessorInterface
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly SubscriptionLifecycleInterface $lifecycle,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function collect(int $invoiceId, bool $customerPresent = false): PaymentOutcome
    {
        return $this->run($invoiceId, scheduled: ! $customerPresent);
    }

    public function pay(Tenant $tenant, User $actor, int $invoiceId): Invoice
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        $invoice = Invoice::query()->find($invoiceId) ?? throw BillingException::invoiceNotFound();

        if ($invoice->status !== InvoiceStatus::Open) {
            throw BillingException::invoiceNotOpen();
        }

        $outcome = $this->run($invoiceId, scheduled: false);

        $this->throwUnlessPaid($outcome, $invoiceId);

        return Invoice::query()->with(['lines', 'payments'])->findOrFail($invoiceId);
    }

    /**
     * Para quien cobra de forma síncrona (alta, cambio de plan, pago manual del cliente): convierte el resultado en error HTTP.
     */
    public function throwUnlessPaid(PaymentOutcome $outcome, int $invoiceId): void
    {
        match ($outcome) {
            PaymentOutcome::Paid => null,
            PaymentOutcome::InProgress => throw BillingException::paymentInProgress(),
            PaymentOutcome::Pending => throw BillingException::paymentPending(),
            PaymentOutcome::RequiresAction => throw $this->requiresAction($invoiceId),
            PaymentOutcome::Skipped => throw BillingException::invoiceNotOpen(),
            PaymentOutcome::Declined => throw $this->declined($invoiceId),
            // Cobrado sobre una factura anulada a la vez: la operación no surte efecto y el cliente lo sabe (el cargo se devuelve).
            PaymentOutcome::Voided => throw BillingException::invoiceVoidedWhileCharging(
                Payment::query()->where('invoice_id', $invoiceId)->where('status', PaymentStatus::Refunded->value)->exists(),
            ),
        };
    }

    public function applyGatewayResult(int $paymentId, ChargeResult $result): PaymentOutcome
    {
        $payment = Payment::query()->findOrFail($paymentId);
        // Igual que el cobro síncrono (`run`): un fallo cuenta en la morosidad solo si el cobro era automático (sin cliente
        // delante). Un «pagar ahora» del cliente sobre una factura de ciclo que falla por webhook NO consume reintentos.
        $scheduled = ! $payment->customer_present;

        $outcome = $this->settle($paymentId, $result, $scheduled);

        if ($outcome === PaymentOutcome::Voided) {
            $this->refund($paymentId);
        }

        return $outcome;
    }

    private function run(int $invoiceId, bool $scheduled): PaymentOutcome
    {
        $claim = $this->claim($invoiceId, $scheduled);

        if ($claim instanceof PaymentOutcome) {
            return $claim;
        }

        [$payment, $invoice] = $claim;

        $result = $this->charge($payment, $invoice);

        $outcome = $this->settle($payment->id, $result, $scheduled);

        if ($outcome === PaymentOutcome::Voided) {
            // Cobrado pero con la factura ya anulada (carrera con una cancelación): se devuelve el dinero ahora mismo;
            // si la pasarela falla, `billing:renew` lo reintenta con la misma clave (`orphanedPayments`).
            $this->refund($payment->id);
        }

        return $outcome;
    }

    public function orphanedPayments(int $limit): array
    {
        $rows = TenantQuery::withoutTenant(fn () => DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('payments.status', PaymentStatus::Succeeded->value)
            ->where('payments.method', 'gateway')
            ->whereNull('payments.refunded_at')
            ->whereNull('payments.deleted_at')
            ->where('invoices.status', InvoiceStatus::Void->value)
            ->orderBy('payments.id')
            ->limit($limit)
            ->get(['payments.id', 'payments.tenant_id']), 'barrido de reembolsos pendientes (billing:renew)');

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'tenant_id' => (int) $r->tenant_id])->all();
    }

    public function refund(int $paymentId): bool
    {
        $payment = Payment::query()->find($paymentId);

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Succeeded || $payment->method !== 'gateway' || $payment->refunded_at !== null) {
            return false;
        }

        // OJO: `Invoice::query()->value('status')` devuelve el enum (cast del modelo), no la cadena: se filtra en SQL.
        if (! Invoice::query()->whereKey($payment->invoice_id)->where('status', InvoiceStatus::Void->value)->exists()) {
            return false;
        }

        if ($payment->gateway_payment_id === null || $payment->gateway !== $this->gateway->name()) {
            LogService::error('billing: cobro a devolver que esta pasarela no puede reembolsar', ['payment_id' => $payment->id, 'gateway' => $payment->gateway], 'security');

            return false;
        }

        // Solo lo que queda por devolver: si alguien ya devolvió una parte desde la pasarela (webhook `charge.refunded`),
        // pedir el total haría que la pasarela lo rechazara siempre y el barrido lo reintentaría para siempre.
        $remaining = Money::of($payment->amount->amountMinor - $payment->refunded_amount_minor, $payment->amount->currency);

        if (! $remaining->isPositive()) {
            return false;
        }

        try {
            $result = $this->gateway->refund(new RefundRequest($payment->gateway_payment_id, $remaining, 'refund-'.$payment->id, 'invoice_void'));
        } catch (IntegrationException $e) {
            LogService::warning('billing: el reembolso falló y se reintentará', ['payment_id' => $payment->id, 'error' => $e::class], 'security');

            return false;
        }

        if (! $result->succeeded) {
            return false;
        }

        return DB::transaction(function () use ($paymentId, $result, $remaining): bool {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->refunded_at !== null) {
                return false;
            }

            $payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now(), 'refund_id' => $result->refundId, 'refunded_amount_minor' => $payment->amount->amountMinor])->save();

            $this->audit->recordAction('billing.payment-refunded', $payment, null, [
                'invoice_id' => $payment->invoice_id, 'refund_id' => $result->refundId, 'amount_minor' => $remaining->amountMinor, 'currency' => $payment->currency,
            ], $payment->tenant_id);

            return true;
        });
    }

    /**
     * @return PaymentOutcome|array{0: Payment, 1: Invoice}
     */
    private function claim(int $invoiceId, bool $scheduled): PaymentOutcome|array
    {
        return DB::transaction(function () use ($invoiceId, $scheduled): PaymentOutcome|array {
            $invoice = Invoice::query()->find($invoiceId) ?? throw BillingException::invoiceNotFound();
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            $lease = now()->addSeconds((int) config('billing.payment.lease_seconds', 120));

            $pending = Payment::query()->where('invoice_id', $invoiceId)->where('status', PaymentStatus::Pending->value)->lockForUpdate()->first();

            // Esperando al cliente (3DS): sigue siendo EL intento en curso; abrir otro cobraría dos veces.
            $awaiting = Payment::query()->where('invoice_id', $invoiceId)->where('status', PaymentStatus::RequiresAction->value)->lockForUpdate()->first();

            if ($awaiting instanceof Payment) {
                return $invoice->status === InvoiceStatus::Open && $subscription->status->isLive() ? PaymentOutcome::RequiresAction : PaymentOutcome::Skipped;
            }

            if ($invoice->status !== InvoiceStatus::Open || ! $subscription->status->isLive()) {
                // Un intento a medias (corte o error transitorio) sobre una factura que se cerró después (baja, alta caducada):
                // la pasarela pudo cobrar. Se resuelve con LA MISMA clave (nunca se abre uno nuevo) y `settle` reembolsa o salda.
                if ($pending instanceof Payment && ! $pending->claimed_until?->isFuture()) {
                    $pending->forceFill(['claimed_until' => $lease, 'tries' => $pending->tries + 1])->save();

                    return [$pending, $invoice];
                }

                return PaymentOutcome::Skipped;
            }

            if ($pending instanceof Payment) {
                if ($pending->claimed_until?->isFuture()) {
                    return PaymentOutcome::InProgress;
                }

                // Arrendamiento vencido (corte o error transitorio): se retoma la MISMA fila y la MISMA clave.
                $pending->forceFill(['claimed_until' => $lease, 'tries' => $pending->tries + 1])->save();

                return [$pending, $invoice];
            }

            $attempt = (int) Payment::query()->where('invoice_id', $invoiceId)->max('attempt') + 1;

            $payment = new Payment;
            $payment->forceFill([
                'invoice_id' => $invoiceId,
                'attempt' => $attempt,
                'method' => 'gateway',
                'gateway' => $this->gateway->name(),
                'status' => PaymentStatus::Pending,
                'amount' => $invoice->total,
                'idempotency_key' => "inv-{$invoiceId}-a{$attempt}",
                'customer_present' => ! $scheduled,
                'claimed_until' => $lease,
                'tries' => 1,
            ])->save();

            return [$payment, $invoice];
        });
    }

    private function charge(Payment $payment, Invoice $invoice): ChargeResult|IntegrationException
    {
        $account = BillingAccount::query()->first();

        if ($payment->tries > 1) {
            // Un intento anterior con esta misma clave pudo llegar a la pasarela y perderse la respuesta (y las claves de
            // Stripe caducan a las 24 h): se pregunta POR CLAVE antes de reenviar. Solo se reenvía si la pasarela confirma que no existe.
            $known = $this->lookup($payment, $account);

            if ($known !== null) {
                return $known;
            }

            if ($invoice->status === InvoiceStatus::Void) {
                // Confirmado que el intento nunca llegó y la factura ya se anuló (baja, alta caducada): no se cobra para
                // reembolsar después (el cliente vería el cargo y Stripe no devuelve su comisión). Una `uncollectible` sí se
                // cobra: es deuda.
                return new ChargeResult(false, '', 'invoice_void', 'La factura se anuló antes de cobrarse.');
            }

            if ($payment->tries > (int) config('billing.payment.max_transient_tries', 5)) {
                // Agotados los intentos: se da por fallido SOLO en una pasada que no envía nada y tras confirmar en la pasarela
                // (ya pasado el backoff) que no existe. Confirmarlo justo después de un envío que dio timeout no vale: la
                // pasarela puede seguir procesándolo y el intento `a+1` (otra clave) cobraría dos veces.
                return new ChargeResult(false, '', 'gateway_unavailable', 'La pasarela no respondió tras varios intentos.');
            }
        }

        if (! $account instanceof BillingAccount || ! $account->hasPaymentMethod() || $account->gateway !== $this->gateway->name()) {
            return new ChargeResult(false, '', 'no_payment_method', 'La empresa no tiene un método de pago válido.');
        }

        try {
            return $this->gateway->charge(new ChargeRequest(
                customerId: (string) $account->gateway_customer_id,
                paymentMethodId: (string) $account->payment_method_id,
                amount: $payment->amount,
                idempotencyKey: $payment->idempotency_key,
                description: 'Factura '.$invoice->number,
                metadata: ['invoice_id' => (string) $invoice->id, 'tenant_id' => (string) $invoice->tenant_id],
                customerPresent: $payment->customer_present,
            ));
        } catch (TransientIntegrationError|RateLimitedError $e) {
            return $e;
        } catch (IntegrationException $e) {
            // Permanente (credenciales, dato inválido, sin cuota): no se reintenta con la misma clave.
            return new ChargeResult(false, '', 'gateway_error', Str::limit($e->getMessage(), 400, ''));
        }
    }

    /**
     * Qué sabe la pasarela de este intento: su resultado, `null` si CONFIRMA que nunca llegó, o el error si no se pudo
     * averiguar (entonces el cobro sigue incierto y no se reenvía).
     */
    private function lookup(Payment $payment, ?BillingAccount $account): ChargeResult|IntegrationException|null
    {
        if ($payment->gateway !== $this->gateway->name()) {
            return null;
        }

        try {
            if ($payment->gateway_payment_id !== null) {
                return $this->gateway->retrieve($payment->gateway_payment_id);
            }

            return $this->gateway->findCharge($account?->gateway_customer_id, $payment->idempotency_key, $payment->created_at->subHour()->getTimestamp());
        } catch (IntegrationException $e) {
            LogService::warning('billing: no se pudo consultar el cobro en la pasarela; sigue pendiente', ['payment_id' => $payment->id, 'error' => $e::class], 'security');

            return $e;
        }
    }

    private function requiresAction(int $invoiceId): BillingException
    {
        $payment = Payment::query()->where('invoice_id', $invoiceId)->where('status', PaymentStatus::RequiresAction->value)->first();
        $secret = null;

        if ($payment instanceof Payment && $payment->gateway_payment_id !== null) {
            try {
                $secret = $this->gateway->retrieve($payment->gateway_payment_id)->clientSecret;
            } catch (IntegrationException) {
                // el cliente puede volver a pedirlo con POST /tenant/billing/invoices/{id}/pay
            }
        }

        return BillingException::paymentRequiresAction($secret, $payment?->gateway_payment_id);
    }

    private function settle(int $paymentId, ChargeResult|IntegrationException $result, bool $scheduled): PaymentOutcome
    {
        return DB::transaction(function () use ($paymentId, $result, $scheduled): PaymentOutcome {
            $payment = Payment::query()->findOrFail($paymentId);
            $subscription = Subscription::query()->whereKey(Invoice::query()->whereKey($payment->invoice_id)->value('subscription_id'))->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
                // Otro proceso ya lo registró (retomó el cobro tras vencer el arrendamiento).
                return match (true) {
                    $payment->status === PaymentStatus::Succeeded && $invoice->status === InvoiceStatus::Paid => PaymentOutcome::Paid,
                    in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true) => PaymentOutcome::Voided,
                    default => PaymentOutcome::Declined,
                };
            }

            if ($result instanceof IntegrationException) {
                // Resultado incierto: sigue `pending` con la misma clave. Pasar a fallido (`gateway_unavailable`) solo lo hace
                // `run()` tras confirmar en la pasarela que el cobro no existe.
                $payment->forceFill(['claimed_until' => now()->addSeconds((int) config('billing.payment.transient_backoff_seconds', 300))])->save();

                return PaymentOutcome::Pending;
            }

            if ($result->processing) {
                // La pasarela aún no decide (p. ej. un débito): el webhook lo resolverá; la conciliación lo vigila.
                $payment->forceFill(['gateway_payment_id' => $result->paymentId, 'claimed_until' => now()->addSeconds((int) config('billing.payment.transient_backoff_seconds', 300))])->save();

                return PaymentOutcome::Pending;
            }

            if ($result->requiresAction) {
                // 3DS: el intento queda a la espera del cliente (índice único: sigue siendo el único en curso de la factura).
                $payment->forceFill([
                    'status' => PaymentStatus::RequiresAction,
                    'gateway_payment_id' => $result->paymentId,
                    'claimed_until' => now()->addHours((int) config('billing.payment.requires_action_hours', 24)),
                ])->save();

                return PaymentOutcome::RequiresAction;
            }

            if ($result->succeeded) {
                $payment->forceFill(['status' => PaymentStatus::Succeeded, 'gateway_payment_id' => $result->paymentId, 'claimed_until' => null, 'processed_at' => now()])->save();

                if ($invoice->status === InvoiceStatus::Open && $invoice->billing_reason === InvoiceReason::Update && ! $this->lifecycle->updateStillApplies($subscription, $invoice)) {
                    // Ajuste de plan cobrado cuando la suscripción ya era otra (renovación o cambio entre medias): no se aplica
                    // y el cobro no se queda; la factura se anula y el pago se reembolsa.
                    $invoice->status = InvoiceStatus::Void;
                    $invoice->voided_at = now();
                    $invoice->save();
                    $this->audit->recordAction('billing.stale-upgrade-payment', $invoice, null, ['subscription_id' => $subscription->id, 'payment_id' => $payment->id], $invoice->tenant_id);
                }

                if ($invoice->status === InvoiceStatus::Void) {
                    // Anulada mientras se cobraba: el cobro no cuenta; `run()` lo reembolsa (y si falla, `billing:renew`).
                    LogService::warning('billing: cobro recibido para una factura anulada; se reembolsa', ['invoice_id' => $invoice->id, 'payment_id' => $payment->id], 'security');

                    return PaymentOutcome::Voided;
                }

                // `open`, o `uncollectible` (baja inmediata o morosidad agotada con un reintento en vuelo): la deuda quedó
                // cobrada, así que la factura pasa a `paid` (como un pago manual tardío). Una suscripción terminada no revive.
                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = now();
                $invoice->save();

                $this->lifecycle->onInvoicePaid($subscription, $invoice);

                InvoicePaid::dispatch($invoice->id, $invoice->tenant_id, $invoice->subscription_id, 'gateway');

                return PaymentOutcome::Paid;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'gateway_payment_id' => $result->paymentId !== '' ? $result->paymentId : null,
                'failure_code' => (string) $result->failureCode,
                'failure_message' => $result->failureMessage !== null ? Str::limit($result->failureMessage, 480, '') : null,
                'claimed_until' => null,
                'processed_at' => now(),
            ])->save();

            $code = (string) $result->failureCode;
            $next = $invoice->status === InvoiceStatus::Open
                ? $this->lifecycle->onPaymentFailed($subscription, $invoice, $code, $scheduled)
                : null;

            if ($invoice->status !== InvoiceStatus::Void) {
                // Sobre una factura anulada no hay nada que avisar al cliente (no debe nada).
                PaymentFailed::dispatch($invoice->id, $invoice->tenant_id, $invoice->subscription_id, $code, $payment->attempt, $next?->toIso8601String());
            }

            return PaymentOutcome::Declined;
        });
    }

    private function declined(int $invoiceId): BillingException
    {
        $payment = Payment::query()->where('invoice_id', $invoiceId)->orderByDesc('attempt')->first();

        return BillingException::paymentFailed((string) ($payment->failure_code ?? 'declined'), $payment?->failure_message);
    }
}

<?php

namespace App\Integrations\Webhooks\Handlers;

use App\Contracts\InboundWebhookHandlerInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Payments\ChargeResult;
use App\Integrations\Payments\StripePaymentGateway;
use App\Models\InboundWebhookReceipt;
use App\Services\LogService;

/**
 * Eventos de Stripe (docs/billing.md §Stripe). Corre en cola, dentro del tenant que resolvió `StripeEventTenantResolver`.
 *
 * Solo traduce el evento y delega en `PaymentReconcilerInterface`, que aplica el estado con la misma lógica que el cobro síncrono:
 * es idempotente (el recibo ya es único por `event.id`, pero además un evento repetido o desordenado no cambia nada).
 *
 *  - payment_intent.succeeded / payment_intent.payment_failed -> estado del intento local.
 *  - charge.refunded                                          -> importe devuelto acumulado (monótono).
 *  - charge.dispute.created                                   -> marca el pago y alerta; ninguna decisión automática.
 *
 * Cualquier otro tipo, o un evento que no corresponde a ningún cobro nuestro, se ignora sin error.
 */
class StripeEventsHandler implements InboundWebhookHandlerInterface
{
    public function __construct(private readonly PaymentReconcilerInterface $reconciler) {}

    public function handle(InboundWebhookReceipt $receipt): void
    {
        $type = (string) ($receipt->payload['type'] ?? '');
        $object = is_array($receipt->payload['data']['object'] ?? null) ? $receipt->payload['data']['object'] : [];

        if ($receipt->tenant_id === null) {
            LogService::info('Stripe: evento sin cobro local conocido, se ignora', ['type' => $type, 'receipt_id' => $receipt->id], 'security');

            return;
        }

        match ($type) {
            'payment_intent.succeeded', 'payment_intent.payment_failed' => $this->reconciler->applyCharge($this->intent($object)),
            'charge.refunded' => $this->refunded($object),
            'charge.dispute.created' => $this->dispute($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function intent(array $object): ChargeResult
    {
        return StripePaymentGateway::fromPaymentIntent($object);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function refunded(array $charge): void
    {
        $pi = $charge['payment_intent'] ?? null;

        if (! is_string($pi) || ! isset($charge['amount_refunded'])) {
            throw new InvalidOutputError('charge.refunded sin payment_intent o importe', 'payments');
        }

        $refunds = is_array($charge['refunds']['data'] ?? null) ? $charge['refunds']['data'] : [];
        $latest = $refunds[0]['id'] ?? null;

        $key = is_array($charge['metadata'] ?? null) ? ($charge['metadata']['idempotency_key'] ?? null) : null;

        $this->reconciler->applyRefund($pi, is_string($key) ? $key : null, StripePaymentGateway::fromStripeAmount((int) $charge['amount_refunded'], (string) ($charge['currency'] ?? '')), is_string($latest) ? $latest : null);
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    private function dispute(array $dispute): void
    {
        $pi = $dispute['payment_intent'] ?? null;
        $id = $dispute['id'] ?? null;

        if (! is_string($pi) || ! is_string($id)) {
            throw new InvalidOutputError('charge.dispute.created sin payment_intent o id', 'payments');
        }

        $this->reconciler->recordDispute($pi, null, $id, isset($dispute['reason']) ? (string) $dispute['reason'] : null);
    }
}

<?php

namespace App\Contracts;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Payments\ChargeResult;

/**
 * Cuadra lo que la pasarela dice con el libro local (docs/billing.md §Conciliación). Todo es idempotente y usa la MISMA
 * lógica que el cobro síncrono (`PaymentProcessorInterface::applyGatewayResult`): da igual que llegue antes el webhook,
 * la respuesta o la conciliación. Los métodos `apply*` y `record*` exigen el tenant del pago en el contexto.
 */
interface PaymentReconcilerInterface
{
    /**
     * Aplica el estado de un cobro de la pasarela (webhook `payment_intent.*` o consulta).
     *
     * @return string `applied` | `already_applied` | `pending` | `declined` | `voided` | `ignored` | `discrepancy`
     */
    public function applyCharge(ChargeResult $charge): string;

    /**
     * Reembolso hecho en la pasarela (webhook `charge.refunded`): importe acumulado devuelto. Monótono: un evento viejo no
     * rebaja lo ya registrado.
     *
     * @return string `applied` | `already_applied` | `discrepancy`
     *
     * @throws IntegrationException transitorio si el cobro aún no está reflejado (el webhook se reintenta)
     */
    public function applyRefund(string $gatewayPaymentId, ?string $idempotencyKey, int $refundedMinor, ?string $refundId): string;

    /**
     * Disputa abierta en la pasarela (`charge.dispute.created`): se marca el pago y se avisa; no se toma ninguna decisión sola.
     *
     * @return string `applied` | `already_applied` | `discrepancy` (disputa de otro PaymentIntent con la misma clave)
     *
     * @throws IntegrationException transitorio si el cobro aún no está reflejado
     */
    public function recordDispute(string $gatewayPaymentId, ?string $idempotencyKey, string $disputeId, ?string $reason): string;

    /**
     * `payments:reconcile`: consulta los cobros inciertos por clave/id, cancela los 3DS vencidos y cuadra los cobros
     * recientes de la pasarela con los locales (de todos los tenants, cada uno dentro de su contexto).
     *
     * @return array<string, int> contadores por resultado
     */
    public function reconcile(): array;
}

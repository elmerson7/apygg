<?php

namespace App\Contracts;

use App\Support\Billing\RenewalOutcome;

/**
 * Ciclo de cobro (docs/billing.md §Renovación). Lo ejecuta `billing:renew`; idempotente y seguro con varios
 * procesos a la vez: bloqueo `FOR UPDATE SKIP LOCKED` por suscripción, índice único (subscription_id, period_start)
 * por factura y arrendamiento + clave de idempotencia por cobro.
 */
interface RenewalServiceInterface
{
    /**
     * Suscripciones (de todos los tenants) con algo que hacer ahora: fin de periodo, reintento vencido,
     * alta sin pagar caducada o cobro transitorio por retomar.
     *
     * @return list<array{id: int, tenant_id: int}>
     */
    public function due(int $limit): array;

    /**
     * Procesa UNA suscripción con su tenant ya en el contexto.
     */
    public function renew(int $subscriptionId): RenewalOutcome;
}

<?php

namespace App\Events\Billing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió el plan de la suscripción (upgrade aplicado o bajada programada que llegó).
 *
 * Se despacha DESPUÉS del commit de la transacción que lo provocó (`ShouldDispatchAfterCommit`): un listener
 * nunca ve un estado que luego se revierte. Solo lleva ids y escalares (sin modelos): es seguro encolarlo
 * y el listener debe abrir el tenant con `TenantContext::run()` si necesita consultar datos. Docs: docs/billing.md.
 */
final class SubscriptionPlanChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $subscriptionId,
        public int $tenantId,
        public string $fromPlanCode,
        public string $toPlanCode,
    ) {}
}

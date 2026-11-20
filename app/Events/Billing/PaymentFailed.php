<?php

namespace App\Events\Billing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un cobro fue rechazado o no pudo hacerse. `nextRetryAt` (ISO 8601) es null si no habrá más reintentos.
 *
 * Se despacha DESPUÉS del commit de la transacción que lo provocó (`ShouldDispatchAfterCommit`): un listener
 * nunca ve un estado que luego se revierte. Solo lleva ids y escalares (sin modelos): es seguro encolarlo
 * y el listener debe abrir el tenant con `TenantContext::run()` si necesita consultar datos. Docs: docs/billing.md.
 */
final class PaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $tenantId,
        public int $subscriptionId,
        public string $failureCode,
        public int $attempt,
        public ?string $nextRetryAt,
    ) {}
}

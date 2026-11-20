<?php

namespace App\Support\Tenancy;

use App\Contracts\SubscriptionPlanSourceInterface;

/** Sin módulo de facturación no hay suscripciones: el plan sale de `tenants.plan` (o del plan por defecto). */
final class NullSubscriptionPlanSource implements SubscriptionPlanSourceInterface
{
    public function planCodeFor(int $tenantId): ?string
    {
        return null;
    }
}

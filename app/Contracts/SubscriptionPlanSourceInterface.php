<?php

namespace App\Contracts;

/**
 * Punto de extensión: de dónde sale el plan «contratado» de un tenant (docs/modules.md §Desacoplamientos).
 *
 * `PlanLimits` (núcleo) pregunta aquí antes de caer en `tenants.plan`. Sin el módulo de facturación el binding
 * por defecto (`NullSubscriptionPlanSource`) devuelve siempre null; con él, la suscripción con derecho a uso manda.
 */
interface SubscriptionPlanSourceInterface
{
    /** Código del plan de la suscripción vigente del tenant, o null si no hay (o no hay facturación). */
    public function planCodeFor(int $tenantId): ?string;
}

<?php

namespace App\Contracts;

use App\Enums\BillingInterval;
use App\Exceptions\BillingException;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;

/**
 * Operaciones sobre la suscripción del tenant (docs/billing.md §Operaciones). Las del tenant exigen que el rol del
 * actor EN ese tenant conceda `tenant-billing.read|manage` (`TenantRoles::actorRankFor`; con equipos de Spatie
 * apagados el `permission:` de la ruta no basta). Las de plataforma (`grantFreePeriod`) solo lleva la ruta `billing.manage`.
 */
interface SubscriptionServiceInterface
{
    /** Suscripción VIVA del tenant (o la última terminada si no hay viva); null si nunca tuvo. */
    public function current(Tenant $tenant, User $actor): ?Subscription;

    /**
     * Contrata un plan. Con prueba (si el plan la tiene y el tenant no la usó antes) no cobra; sin prueba y con
     * precio, cobra ya (el alta queda `incomplete` si el cobro falla).
     *
     * @throws BillingException `subscription_exists`, `plan_not_found`, `plan_private`, `price_unavailable`, `payment_method_required`, `payment_failed`
     */
    public function subscribe(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval, ?string $paymentToken): Subscription;

    /**
     * La plataforma (`billing.manage`) asigna un plan, público o PRIVADO, a un tenant: lo contrata si no tiene suscripción
     * viva o se lo cambia (mismas reglas de prueba, prorrateo, cobro y método de pago que el tenant). Motivo obligatorio,
     * auditado como `billing.plan-assigned` en la misma transacción.
     *
     * @throws BillingException las de `subscribe()` / `changePlan()`
     */
    public function assignPlan(int $tenantId, User $actor, string $planCode, ?string $currency, BillingInterval $interval, string $reason): Subscription;

    /**
     * Qué pasaría al cambiar de plan, sin hacerlo: modo (`trial`, `immediate`, `scheduled`), prorrateo e inicio efectivo.
     *
     * @return array<string, mixed>
     */
    public function previewChange(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval): array;

    /**
     * Cambia de plan: subida (más cara, misma moneda e intervalo) inmediata con prorrateo en enteros; el resto, al final del periodo.
     *
     * @throws BillingException `subscription_not_active`, `plan_private`, `same_plan`, `change_in_progress`, `payment_failed`
     */
    public function changePlan(Tenant $tenant, User $actor, string $planCode, ?string $currency, BillingInterval $interval): Subscription;

    /** Baja al final del periodo, o inmediata. Idempotente: repetirla no cambia nada. */
    public function cancel(Tenant $tenant, User $actor, bool $immediately): Subscription;

    /**
     * Deshace una baja pendiente antes de que termine el periodo.
     *
     * @throws BillingException `subscription_not_resumable`
     */
    public function resume(Tenant $tenant, User $actor): Subscription;

    /**
     * Periodo gratis (admin de plataforma): alarga N días el periodo actual de una suscripción en prueba o activa.
     * Auditado (`billing.free-period`, motivo obligatorio).
     *
     * @throws BillingException `invalid_free_period`, `subscription_not_active`, `subscription_not_found`
     */
    public function grantFreePeriod(int $tenantId, User $actor, int $days, string $reason): Subscription;
}

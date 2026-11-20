<?php

namespace Tests\Support;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\RenewalOutcome;

/**
 * Ayudas para probar facturación: tenants A/B con dueño, admin y miembro, catálogo sincronizado y la pasarela falsa.
 * Requiere `AssertsTenantIsolation` (+ `InteractsWithTenancy`, `ActsWithHeaders`) en el mismo test.
 */
trait InteractsWithBilling
{
    public function bootBilling(): void
    {
        $this->bootIsolation();
        config(['queue.default' => 'sync', 'plans.enabled' => true]);
        app(PlanCatalogInterface::class)->sync();
    }

    public function gateway(): FakePaymentGateway
    {
        $gateway = app(PaymentGatewayInterface::class);
        assert($gateway instanceof FakePaymentGateway);

        return $gateway;
    }

    /** Contrata un plan como `$actor` dentro del tenant (a nivel de servicio). */
    public function subscribeTo(Tenant $tenant, User $actor, string $plan = 'pro', string $interval = 'month', ?string $token = 'tok_visa', ?string $currency = 'USD'): Subscription
    {
        return $this->ctx->run($tenant, fn () => app(SubscriptionServiceInterface::class)->subscribe($tenant, $actor, $plan, $currency, BillingInterval::from($interval), $token));
    }

    public function renewNow(Tenant $tenant, int $subscriptionId): RenewalOutcome
    {
        return $this->ctx->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($subscriptionId));
    }

    /** Hace público un plan del catálogo (p. ej. `enterprise`, privado en la config) para que el tenant lo contrate solo. */
    public function publishPlan(string $code): void
    {
        Plan::query()->where('code', $code)->update(['is_public' => true]);
    }

    /** La suscripción tal como está en BD ahora. */
    public function fresh(Tenant $tenant, int $subscriptionId): Subscription
    {
        return $this->ctx->run($tenant, fn () => Subscription::query()->findOrFail($subscriptionId));
    }
}

<?php

use App\Contracts\FeatureFlagServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->bootBilling();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    config(['plans.plans.pro.limits.max_api_keys' => null]);
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
});

/** Fija el estado saltándose la máquina de transiciones (solo para preparar el escenario). */
function blEstado($test, Tenant $tenant, SubscriptionStatus $status, array $extra = []): Subscription
{
    $sub = $test->subscribeTo($tenant, $tenant->id === $test->a->id ? $test->ownerA : $test->ownerB);
    DB::table('subscriptions')->where('id', $sub->id)->update(['status' => $status->value] + $extra);

    return $sub;
}

function blEscribe($test, $tenant = null, $owner = null)
{
    $tenant ??= $test->a;
    $owner ??= $tenant->id === $test->a->id ? $test->ownerA : $test->ownerB;

    return $test->isoCall($test->isoHeaders($owner, $tenant), 'POST', '/api-keys', ['name' => 'k'.uniqid()]);
}

function blLee($test)
{
    return $test->isoCall($test->isoHeaders($test->ownerA, $test->a), 'GET', '/api-keys');
}

test('con la suscripción activa o en prueba se puede escribir sin avisos', function () {
    blEstado($this, $this->a, SubscriptionStatus::Active);
    blEscribe($this)->assertCreated()->assertHeaderMissing('X-Subscription-Warning');

    DB::table('subscriptions')->where('tenant_id', $this->a->id)->update(['status' => 'trialing']);
    blEscribe($this)->assertCreated();
});

test('past_due dentro de la gracia permite escribir con la cabecera de aviso', function () {
    blEstado($this, $this->a, SubscriptionStatus::PastDue);

    $res = blEscribe($this)->assertCreated();
    expect($res->headers->get('X-Subscription-Warning'))->toStartWith('past_due; grace_ends_at=');
});

test('past_due pasada la gracia queda en solo lectura con 402 payment_required y la acción sugerida', function () {
    blEstado($this, $this->a, SubscriptionStatus::PastDue, ['current_period_start' => now()->subDays(8)]);

    blEscribe($this)->assertStatus(402)
        ->assertJsonPath('code', 'payment_required')
        ->assertJsonPath('subscription_status', 'past_due')
        ->assertJsonPath('suggested_action.action', 'pay_invoice');

    blLee($this)->assertOk();
});

test('la gracia es configurable', function () {
    config(['billing.enforcement.past_due_grace_days' => 0]);
    blEstado($this, $this->a, SubscriptionStatus::PastDue);

    blEscribe($this)->assertStatus(402)->assertJsonPath('code', 'payment_required');
});

test('canceled y expired quedan en solo lectura con 402 subscription_inactive', function () {
    blEstado($this, $this->a, SubscriptionStatus::Canceled);
    blEscribe($this)->assertStatus(402)
        ->assertJsonPath('code', 'subscription_inactive')
        ->assertJsonPath('suggested_action.action', 'resubscribe');
    blLee($this)->assertOk();

    DB::table('subscriptions')->where('tenant_id', $this->a->id)->update(['status' => 'expired']);
    blEscribe($this)->assertStatus(402)->assertJsonPath('code', 'subscription_inactive');
});

test('incomplete queda en solo lectura con 402 payment_required', function () {
    blEstado($this, $this->a, SubscriptionStatus::Incomplete);

    blEscribe($this)->assertStatus(402)->assertJsonPath('code', 'payment_required');
});

test('en solo lectura siguen abiertas las rutas de facturación para poder pagar', function () {
    blEstado($this, $this->a, SubscriptionStatus::Canceled);
    $h = $this->isoHeaders($this->ownerA, $this->a);

    $this->isoCall($h, 'GET', '/tenant/billing/invoices')->assertOk();
    // Reactivar la suscripción no lo frena el bloqueo (puede fallar por otra razón de negocio, nunca por 402 de bloqueo).
    $res = $this->isoCall($h, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_visa']);
    expect($res->json('code'))->not->toBe('subscription_inactive');
});

test('la lista blanca es configurable', function () {
    blEstado($this, $this->a, SubscriptionStatus::Canceled);
    config(['billing.enforcement.allow_paths' => ['api-keys']]);

    blEscribe($this)->assertCreated();
});

test('sin suscripción: por defecto actúa como plan gratuito y con require queda en solo lectura', function () {
    blEscribe($this)->assertCreated();

    config(['billing.enforcement.no_subscription' => 'require']);
    blEscribe($this)->assertStatus(402)
        ->assertJsonPath('code', 'subscription_required')
        ->assertJsonPath('suggested_action.action', 'choose_plan');
    blLee($this)->assertOk();
});

test('apagar el bloqueo deja escribir con cualquier estado', function () {
    config(['billing.enforcement.enabled' => false]);
    blEstado($this, $this->a, SubscriptionStatus::Canceled);

    blEscribe($this)->assertCreated();
});

test('el estado de A no afecta a B', function () {
    blEstado($this, $this->a, SubscriptionStatus::Canceled);
    blEstado($this, $this->b, SubscriptionStatus::Active);

    blEscribe($this)->assertStatus(402);
    blEscribe($this, $this->b)->assertCreated();
    expect(TenantQuery::withoutTenant(fn () => Subscription::query()->where('tenant_id', $this->b->id)->first()->status))->toBe(SubscriptionStatus::Active);
});

test('una suscripción nueva tras cancelar levanta el bloqueo', function () {
    blEstado($this, $this->a, SubscriptionStatus::Canceled);
    blEscribe($this)->assertStatus(402);

    $this->subscribeTo($this->a, $this->ownerA);
    blEscribe($this)->assertCreated();
});

// ── Límites por plan ─────────────────────────────────────────────────────────

test('invitar respeta max_members del plan (miembros más invitaciones pendientes) y el cupo es por tenant', function () {
    Notification::fake();
    $this->isoUser($this->a, 'admin');
    $this->isoUser($this->a, 'member'); // 3 miembros = tope de free
    $h = $this->isoHeaders($this->ownerA, $this->a);

    $this->isoCall($h, 'POST', '/tenant/invitations', ['email' => 'x@example.com'])
        ->assertForbidden()->assertJsonPath('code', 'plan_limit_reached')->assertJsonPath('limit_key', 'max_members');

    // B tiene su propio cupo (solo su dueño).
    $this->isoCall($this->isoHeaders($this->ownerB, $this->b), 'POST', '/tenant/invitations', ['email' => 'x@example.com'])->assertCreated();
});

test('al bajar de plan no se borra nada: lo que excede se conserva y solo se frena el alta nueva', function () {
    config(['plans.plans.pro.limits.max_api_keys' => 20]);
    blEstado($this, $this->a, SubscriptionStatus::Active);
    foreach (range(1, 4) as $i) {
        blEscribe($this)->assertCreated();
    }

    DB::table('subscriptions')->where('tenant_id', $this->a->id)->update(['plan_code' => 'free']);
    app(PlanLimitsInterface::class)->refresh($this->a->id);

    blLee($this)->assertOk()->assertJsonCount(4, 'data');
    blEscribe($this)->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');
});

// ── Features por plan ────────────────────────────────────────────────────────

test('una feature declarada en un plan solo la tienen los tenants de ese plan; un override de plataforma manda', function () {
    config([
        'features.export_users.enabled' => true,
        'plans.plans.pro.features' => ['export_users'],
        'plans.plans.enterprise.features' => ['*'],
    ]);
    $flags = app(FeatureFlagServiceInterface::class);
    $limits = app(PlanLimitsInterface::class);

    expect($flags->enabledFor('export_users', null, $this->a->id))->toBeFalse(); // free: no la incluye

    $limits->setPlan($this->a->id, 'pro');
    expect($flags->enabledFor('export_users', null, $this->a->id))->toBeTrue()
        ->and($flags->enabledFor('export_users', null, $this->b->id))->toBeFalse();

    $limits->setPlan($this->b->id, 'enterprise');
    expect($flags->enabledFor('export_users', null, $this->b->id))->toBeTrue();

    // Una flag que ningún plan declara no depende del plan.
    expect($flags->enabledFor('advanced_logging', null, $this->a->id))->toBeTrue();

    // Con la flag apagada globalmente, ni el plan la enciende.
    config(['features.export_users.enabled' => false]);
    expect($flags->enabledFor('export_users', null, $this->a->id))->toBeFalse();
});

test('la ruta con feature: respeta el plan del tenant', function () {
    config(['features.export_users.enabled' => true, 'plans.plans.pro.features' => ['export_users']]);
    $limits = app(PlanLimitsInterface::class);
    $flags = app(FeatureFlagServiceInterface::class);

    $this->ctx->run($this->a, fn () => expect($flags->disabled('export_users'))->toBeTrue());
    $limits->setPlan($this->a->id, 'pro');
    $this->ctx->run($this->a, fn () => expect($flags->enabled('export_users'))->toBeTrue());
});

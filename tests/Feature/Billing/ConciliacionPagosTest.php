<?php

use App\Contracts\AuditServiceInterface;
use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeResult;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithStripe;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class, InteractsWithStripe::class);

beforeEach(function () {
    $this->bootBilling();
    $this->owner = $this->isoUser($this->a, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
});

/** Alta con Stripe y salto al fin del periodo para que la renovación (off_session) esté vencida. */
function cpRenovacionVencida($test): Subscription
{
    $test->useStripe();
    $sub = $test->subscribeTo($test->a, $test->owner, token: 'pm_card_ok');
    $test->travelTo($sub->current_period_end->copy()->addMinute());

    return $sub;
}

test('con la pasarela falsa, un cobro cuya respuesta se perdió se resuelve consultando por clave: un solo cobro y nunca otro intento', function () {
    $sub = $this->subscribeTo($this->a, $this->owner);
    $this->travelTo($sub->current_period_end->copy()->addMinute());
    $this->gateway()->loseResponseNext();

    expect($this->renewNow($this->a, $sub->id)->value)->toBe('retrying');
    $this->travelTo(now()->addMinutes(10));
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('renewed');

    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(2)->and(Payment::query()->count())->toBe(2));
    // Alta + renovación: la renovación se envió UNA vez; el reintento preguntó por la clave en vez de reenviar.
    expect($this->gateway()->calls('charge'))->toHaveCount(2)->and($this->gateway()->calls('findCharge'))->toHaveCount(1);
});

test('la clave de Stripe caducó (24 h) y la respuesta se perdió: se encuentra el cobro por la metadata y NO se cobra dos veces', function () {
    $sub = cpRenovacionVencida($this);
    $this->stripe->loseNext();

    expect($this->renewNow($this->a, $sub->id)->value)->toBe('retrying');

    $this->travelTo(now()->addHours(30));
    $this->stripe->expireKeys();   // sin la defensa, reenviar la misma clave crearía un segundo PaymentIntent
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('renewed');

    expect($this->stripe->succeeded())->toHaveCount(2)   // alta + una renovación
        ->and(collect($this->stripe->requests)->where('method', 'POST')->where('path', '/payment_intents'))->toHaveCount(2 + 0);
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(2));
});

test('agotados los intentos transitorios NO se da por fallido si el cobro existe en la pasarela: se salda y no hay segundo cobro', function () {
    config(['billing.payment.max_transient_tries' => 2]);
    $sub = cpRenovacionVencida($this);
    $this->stripe->loseNext(2);

    $this->renewNow($this->a, $sub->id);
    $this->travelTo(now()->addMinutes(6));
    // Segundo intento: la clave ya está guardada en Stripe (replay), la respuesta vuelve a perderse; se agotan los intentos.
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('renewed');

    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'failed')->count())->toBe(0)->and(Payment::query()->where('status', 'succeeded')->count())->toBe(2));
    expect($this->stripe->succeeded())->toHaveCount(2);
});

test('agotados los intentos y sin poder consultar la pasarela el cobro sigue pendiente: no se abre otro intento con otra clave', function () {
    config(['billing.payment.max_transient_tries' => 2]);
    $sub = cpRenovacionVencida($this);
    $this->stripe->loseNext(2);
    $this->renewNow($this->a, $sub->id);
    $this->travelTo(now()->addMinutes(6));
    $this->stripe->failListing = true;

    expect($this->renewNow($this->a, $sub->id)->value)->toBe('retrying');
    $this->travelTo(now()->addMinutes(6));
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('retrying');

    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'pending')->count())->toBe(1)->and(Payment::query()->where('status', 'failed')->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(2));

    // Cuando la consulta vuelve a funcionar, se resuelve con el cobro real.
    $this->stripe->failListing = false;
    $this->travelTo(now()->addMinutes(6));
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('renewed');
    expect($this->stripe->succeeded())->toHaveCount(2);
});

test('payments:reconcile resuelve un cobro pendiente con la respuesta y el webhook perdidos', function () {
    $sub = cpRenovacionVencida($this);
    $this->stripe->loseNext();
    $this->renewNow($this->a, $sub->id);
    $this->travelTo(now()->addMinutes(20));
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('stale_applied: 1')->assertSuccessful();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(2)->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active));
    $this->artisan('payments:reconcile')->assertSuccessful();   // idempotente
    expect($this->stripe->succeeded())->toHaveCount(2);
});

test('un cobro de Stripe sin reflejo local queda como discrepancia con alerta, una sola vez, y se resuelve con nota', function () {
    $this->useStripe();
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $cliente = $this->stripe->intent('pi_2')['customer'];
    $this->stripe->injectSucceeded($cliente, 2900, 'usd', ['idempotency_key' => 'inv-999-a1', 'tenant_id' => (string) $this->a->id]);
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('recent_discrepancy: 1')->assertSuccessful();
    $this->artisan('payments:reconcile')->assertSuccessful();

    $d = $this->ctx->run($this->a, fn () => PaymentDiscrepancy::query()->sole());
    expect($d->kind)->toBe('succeeded_without_local')->and($d->status)->toBe('open')->and($d->amount_minor)->toBe(2900);

    $admin = $this->isoUser($this->a, 'owner');
    $this->mock(AuditServiceInterface::class)->shouldIgnoreMissing();
    $this->ctx->run($this->a, fn () => app(PaymentDiscrepancyServiceInterface::class)->resolve($d->id, $admin, 'Reembolsado a mano en el panel de Stripe'));
    expect($this->ctx->run($this->a, fn () => PaymentDiscrepancy::query()->sole()->status))->toBe('resolved');
});

test('un cobro con la metadata de un tenant pero el cliente de otro NO se atribuye a nadie (no se confía en la metadata sola)', function () {
    $this->useStripe();
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $this->stripe->injectSucceeded('cus_ajeno', 2900, 'usd', ['idempotency_key' => 'inv-999-a1', 'tenant_id' => (string) $this->a->id]);
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('foreign_charge: 1')->assertSuccessful();
    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(0));
});

test('un segundo PaymentIntent cobrado para el mismo intento local se registra como doble cobro y no toca el libro', function () {
    $this->useStripe();
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $pago = $this->stripePayment($this->a);
    $this->stripe->injectSucceeded($this->stripe->intent('pi_2')['customer'], 2900, 'usd', ['idempotency_key' => $pago->idempotency_key, 'tenant_id' => (string) $this->a->id]);
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->assertSuccessful();

    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->sole()->kind)->toBe('duplicate_charge')->and(Payment::query()->count())->toBe(1));
});

test('un 3DS que el cliente no confirmó en el plazo se cancela en la pasarela y el intento falla (action_expired)', function () {
    $this->useStripe();

    try {
        $this->subscribeTo($this->a, $this->owner, token: 'pm_card_3ds');
    } catch (BillingException) {
    }
    $this->travelTo(now()->addHours(25));
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('action_declined: 1')->assertSuccessful();

    expect($this->stripe->intent('pi_2')['status'])->toBe('canceled');
    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->failure_code)->toBe('action_expired')->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Open));
});

test('si el cliente confirmó el 3DS justo antes de expirar, la conciliación respeta el cobro real en vez de cancelarlo', function () {
    $this->useStripe();

    try {
        $this->subscribeTo($this->a, $this->owner, token: 'pm_card_3ds');
    } catch (BillingException) {
    }
    $this->stripe->confirm('pi_2');   // cobrado en Stripe; el webhook se perdió
    $this->travelTo(now()->addHours(25));
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->assertSuccessful();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->status->value)->toBe('succeeded')->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active));
});

test('un fallo transitorio de la pasarela al conciliar no rompe nada y se reintenta', function () {
    $sub = $this->subscribeTo($this->a, $this->owner);
    $this->travelTo($sub->current_period_end->copy()->addMinute());
    $this->gateway()->loseResponseNext();
    $this->renewNow($this->a, $sub->id);
    $this->travelTo(now()->addMinutes(20));
    $this->gateway()->failNextLookup(TransientIntegrationError::class, 1);
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('stale_unresolved: 1')->assertSuccessful();
    // La fase de cobros recientes lo cuadra aunque la consulta por clave fallara: el pago queda cobrado una sola vez.
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(2));
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect($this->gateway()->calls('charge'))->toHaveCount(2);
});

test('sin pasarela (PAYMENTS_MODE=null) la conciliación no hace nada y termina bien', function () {
    config(['integrations.payments.mode' => 'null']);
    app()->forgetInstance(PaymentGatewayInterface::class);
    $this->ctx->clear();

    $this->artisan('payments:reconcile')->expectsOutputToContain('skipped_no_gateway')->assertSuccessful();
});

test('la bandeja de discrepancias es solo de plataforma: sin permiso billing.manage responde 403', function () {
    $this->actingAs($this->owner, 'api')->getJson('/admin/billing/discrepancies')->assertStatus(403);
});

test('un «pagar ahora» con 3DS sobre una factura de renovación que falla por webhook NO consume reintentos de morosidad (igual que si fallara en síncrono)', function () {
    $sub = $this->subscribeTo($this->a, $this->owner);
    $this->travelTo($sub->current_period_end->copy()->addMinute());
    $this->gateway()->declineNext();
    expect($this->renewNow($this->a, $sub->id)->value)->toBe('payment_failed')
        ->and($this->fresh($this->a, $sub->id)->renewal_attempts)->toBe(1);

    $this->ctx->run($this->a, fn () => app(BillingAccountServiceInterface::class)->setPaymentMethod($this->a, $this->owner, 'tok_3ds'));
    $factura = $this->ctx->run($this->a, fn () => Invoice::query()->where('billing_reason', 'subscription_cycle')->sole());
    expect(fn () => $this->ctx->run($this->a, fn () => app(PaymentProcessorInterface::class)->pay($this->a, $this->owner, $factura->id)))
        ->toThrow(fn (BillingException $e) => expect($e->errorCode())->toBe('payment_requires_action'));

    $pago = $this->ctx->run($this->a, fn () => Payment::query()->where('status', 'requires_action')->sole());
    expect($pago->customer_present)->toBeTrue();

    // El cliente no supera el 3DS: llega el fallo por webhook (misma lógica que applyCharge).
    $this->ctx->run($this->a, fn () => app(PaymentReconcilerInterface::class)->applyCharge(new ChargeResult(false, (string) $pago->gateway_payment_id, 'payment_intent_authentication_failure', 'No autenticó', amountMinor: $pago->amount->amountMinor, currency: $pago->currency, idempotencyKey: $pago->idempotency_key)));

    $s = $this->fresh($this->a, $sub->id);
    expect($s->renewal_attempts)->toBe(1)->and($s->status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->stripePayment($this->a, $pago->id)->status->value)->toBe('failed');
});

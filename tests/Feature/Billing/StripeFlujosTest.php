<?php

use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoicePaid;
use App\Exceptions\BillingException;
use App\Jobs\ProcessInboundWebhookJob;
use App\Models\AuditLog;
use App\Models\InboundWebhookReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithStripe;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class, InteractsWithStripe::class);

beforeEach(function () {
    $this->bootBilling();
    $this->useStripe();
    $this->owner = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
});

function sfCodigo(string $code): Closure
{
    return fn (BillingException $e) => expect($e->errorCode())->toBe($code);
}

function sfAlta($test, string $pm, $tenant = null, $owner = null)
{
    return fn () => $test->subscribeTo($tenant ?? $test->a, $owner ?? $test->owner, token: $pm);
}

test('el alta cobra por Stripe con la clave de idempotencia del intento y deja la suscripción activa', function () {
    $sub = $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    $pago = $this->stripePayment($this->a);
    expect($pago->gateway)->toBe('stripe')->and($pago->gateway_payment_id)->toBe('pi_2')->and($pago->status->value)->toBe('succeeded')
        ->and($pago->customer_present)->toBeTrue();
    $cobro = collect($this->stripe->requests)->firstWhere('path', '/payment_intents');
    expect($cobro['key'])->toBe($pago->idempotency_key)->and($cobro['body']['amount'])->toBe('2900')->and($cobro['body'])->not->toHaveKey('off_session');
});

test('un rechazo del banco deja la suscripción incomplete y responde 402 payment_failed con el motivo', function () {
    expect(sfAlta($this, 'pm_card_declined'))->toThrow(function (BillingException $e) {
        expect($e->errorCode())->toBe('payment_failed')->and($e->getExtensions()['failure_code'])->toBe('insufficient_funds');
    });

    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Incomplete)->and(Payment::query()->sole()->status->value)->toBe('failed'));
});

test('3DS: el cobro con el cliente delante queda requires_action con el client_secret y NO abre otro intento al reintentar', function () {
    expect(sfAlta($this, 'pm_card_3ds'))->toThrow(function (BillingException $e) {
        expect($e->errorCode())->toBe('payment_requires_action')->and($e->getStatusCode())->toBe(402)
            ->and($e->getExtensions()['client_secret'])->toStartWith('pi_')->and($e->getExtensions()['payment_intent_id'])->toBe('pi_2');
    });

    $this->ctx->run($this->a, function () {
        expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Incomplete)
            ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Open)
            ->and(Payment::query()->sole()->status->value)->toBe('requires_action');
    });

    // «Pagar ahora» otra vez: devuelve el mismo PaymentIntent y su client_secret, sin cobrar de nuevo.
    $factura = $this->ctx->run($this->a, fn () => Invoice::query()->sole());
    expect(fn () => $this->ctx->run($this->a, fn () => app(PaymentProcessorInterface::class)->pay($this->a, $this->owner, $factura->id)))->toThrow(sfCodigo('payment_requires_action'));
    expect($this->stripe->intents)->toHaveCount(1)->and(collect($this->stripe->requests)->where('path', '/payment_intents')->where('method', 'POST'))->toHaveCount(1);
});

test('3DS completado: el webhook payment_intent.succeeded activa la suscripción y un evento repetido no cambia nada', function () {
    Event::fake([InvoicePaid::class]);

    try {
        $this->subscribeTo($this->a, $this->owner, token: 'pm_card_3ds');
    } catch (BillingException) {
    }

    $this->stripe->confirm('pi_2');
    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'), 'evt_a')->assertOk()->assertJsonPath('data.status', 'received');
    // El mismo evento reentregado, y otro evento distinto con el mismo cobro:
    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'), 'evt_a')->assertOk()->assertJsonPath('data.status', 'duplicate');
    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'), 'evt_b')->assertOk();

    $this->ctx->run($this->a, function () {
        expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)
            ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->where('status', 'succeeded')->count())->toBe(1);
    });
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
    expect(InboundWebhookReceipt::query()->where('provider', 'stripe')->pluck('status')->unique()->all())->toBe(['processed']);
    // El client_secret del PaymentIntent no se guarda en el recibo (strip_payload); el resto sí.
    $guardado = InboundWebhookReceipt::query()->where('provider', 'stripe')->firstOrFail()->payload['data']['object'];
    expect($guardado)->not->toHaveKey('client_secret')->and($guardado['id'])->toBe('pi_2');
});

test('3DS abandonado: payment_failed marca el intento fallido, la factura del alta sigue abierta y se puede reintentar', function () {
    try {
        $this->subscribeTo($this->a, $this->owner, token: 'pm_card_3ds');
    } catch (BillingException) {
    }

    $pi = $this->stripe->intent('pi_2');
    $pi['status'] = 'requires_payment_method';
    $pi['last_payment_error'] = ['code' => 'payment_intent_authentication_failure', 'message' => 'El cliente no autenticó'];
    $this->stripeWebhook('payment_intent.payment_failed', $pi)->assertOk();

    $this->ctx->run($this->a, function () {
        $pago = Payment::query()->sole();
        expect($pago->status->value)->toBe('failed')->and($pago->failure_code)->toBe('payment_intent_authentication_failure')
            ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Open)
            ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Incomplete);
    });
    // El PaymentIntent se cancela en Stripe: el front ya no puede reconfirmarlo mientras se abre otro intento (doble cobro).
    expect($this->stripe->intent('pi_2')['status'])->toBe('canceled');
});

test('3DS: si el cliente ya reconfirmó el pago cuando llega el payment_failed, se aplica el cobro en vez de marcarlo fallido', function () {
    try {
        $this->subscribeTo($this->a, $this->owner, token: 'pm_card_3ds');
    } catch (BillingException) {
    }

    $fallo = $this->stripe->intent('pi_2');
    $fallo['status'] = 'requires_payment_method';
    $fallo['last_payment_error'] = ['code' => 'payment_intent_authentication_failure'];
    $this->stripe->confirm('pi_2');   // reconfirmado con otra tarjeta antes de que procesemos el evento viejo

    $this->stripeWebhook('payment_intent.payment_failed', $fallo)->assertOk();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->status->value)->toBe('succeeded')
        ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active));
});

test('un plan asignado por la plataforma se cobra off_session: sin 3DS ni client_secret para el admin', function () {
    $this->ctx->run($this->a, fn () => app(BillingAccountServiceInterface::class)->setPaymentMethod($this->a, $this->owner, 'pm_card_3ds'));
    $admin = User::factory()->create();
    $admin->assignRole(Role::where('name', 'admin')->first());
    $this->ctx->clear();

    expect(fn () => app(SubscriptionServiceInterface::class)->assignPlan($this->a->id, $admin, 'pro', 'USD', BillingInterval::Month, 'Acuerdo firmado'))
        ->toThrow(function (BillingException $e) {
            expect($e->errorCode())->toBe('payment_failed')->and($e->getExtensions()['failure_code'])->toBe('authentication_required')
                ->and($e->getExtensions())->not->toHaveKey('client_secret');
        });

    $cobro = collect($this->stripe->requests)->where('method', 'POST')->firstWhere('path', '/payment_intents');
    expect($cobro['body']['off_session'])->toBe('true')->and($this->stripePayment($this->a)->customer_present)->toBeFalse();
});

test('un reembolso de OTRO PaymentIntent con la misma clave (un duplicado) no marca como devuelto el cobro local: queda como discrepancia', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $pago = $this->stripePayment($this->a);

    $this->stripeWebhook('charge.refunded', ['id' => 'ch_9', 'object' => 'charge', 'payment_intent' => 'pi_dup', 'amount_refunded' => 2900, 'currency' => 'usd',
        'metadata' => ['idempotency_key' => $pago->idempotency_key], 'refunds' => ['data' => [['id' => 're_9']]]])->assertOk();

    $this->ctx->run($this->a, function () {
        $p = Payment::query()->sole();
        $d = PaymentDiscrepancy::query()->sole();
        expect($p->status->value)->toBe('succeeded')->and($p->refunded_amount_minor)->toBe(0)->and($p->refunded_at)->toBeNull()
            ->and($d->kind)->toBe('refunded_externally')->and($d->details['payment_intent'])->toBe('pi_dup')->and($d->details['kept'])->toBe('pi_2');
    });
});

test('el reembolso automático de una factura anulada devuelve solo lo que queda si ya se devolvió una parte en Stripe', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $this->ctx->run($this->a, fn () => Invoice::query()->update(['status' => 'void', 'voided_at' => now()]));
    $this->stripeWebhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_2', 'amount_refunded' => 1000, 'currency' => 'usd', 'refunds' => ['data' => [['id' => 're_1']]]])->assertOk();

    $this->ctx->run($this->a, fn () => expect(app(PaymentProcessorInterface::class)->refund(Payment::query()->sole()->id))->toBeTrue());

    expect($this->stripe->refunds)->toHaveCount(1)->and($this->stripe->refunds[0]['amount'])->toBe('1900');
    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->status->value)->toBe('refunded')->and(Payment::query()->sole()->refunded_amount_minor)->toBe(2900));
});

test('si el webhook llega ANTES que la respuesta (que se pierde), el resultado es el mismo y billing:renew no cobra otra vez', function () {
    $this->stripe->loseNext();
    expect(sfAlta($this, 'pm_card_ok'))->toThrow(sfCodigo('payment_pending'));

    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->status->value)->toBe('pending')->and(Payment::query()->sole()->gateway_payment_id)->toBeNull());

    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'))->assertOk();

    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(Payment::query()->sole()->gateway_payment_id)->toBe('pi_2')->and(Payment::query()->sole()->status->value)->toBe('succeeded'));

    $this->travelTo(now()->addMinutes(30));
    $this->ctx->clear();
    $this->artisan('billing:renew')->assertSuccessful();
    expect($this->stripe->succeeded())->toBe(['pi_2'])->and($this->stripe->intents)->toHaveCount(1);
});

test('si la respuesta llega antes que el webhook, el evento posterior no cambia nada', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $antes = $this->ctx->run($this->a, fn () => Payment::query()->sole()->only(['status', 'processed_at']));

    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'))->assertOk();

    expect($this->ctx->run($this->a, fn () => Payment::query()->sole()->only(['status', 'processed_at'])))->toEqual($antes);
    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(0));
});

test('un payment_failed tardío no deshace un cobro ya exitoso', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $pi = $this->stripe->intent('pi_2');
    $pi['status'] = 'requires_payment_method';
    $pi['last_payment_error'] = ['code' => 'card_declined'];

    $this->stripeWebhook('payment_intent.payment_failed', $pi)->assertOk();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->status->value)->toBe('succeeded')->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Paid));
});

test('un webhook con importe distinto al local no se aplica y queda registrado como discrepancia', function () {
    $this->stripe->loseNext();
    expect(sfAlta($this, 'pm_card_ok'))->toThrow(sfCodigo('payment_pending'));

    $pi = $this->stripe->intent('pi_2');
    $pi['amount'] = 100;
    $this->stripeWebhook('payment_intent.succeeded', $pi)->assertOk();

    $this->ctx->run($this->a, function () {
        expect(Payment::query()->sole()->status->value)->toBe('pending')->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Open)
            ->and(PaymentDiscrepancy::query()->sole()->kind)->toBe('data_mismatch');
    });
});

test('firma inválida, caducada o ausente: 401 con code estable y no se guarda nada', function () {
    $pi = ['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1, 'currency' => 'usd'];

    $this->stripeWebhook('payment_intent.succeeded', $pi, over: ['secret' => 'whsec_otro'])->assertStatus(401)->assertJsonPath('code', 'webhook_signature_invalid');
    $this->stripeWebhook('payment_intent.succeeded', $pi, over: ['ts' => time() - 4000])->assertStatus(401)->assertJsonPath('code', 'webhook_timestamp_expired');
    $this->stripeWebhook('payment_intent.succeeded', $pi, over: ['drop' => ['HTTP_STRIPE_SIGNATURE']])->assertStatus(401)->assertJsonPath('code', 'webhook_signature_missing');
    expect(InboundWebhookReceipt::query()->count())->toBe(0);
});

test('sin STRIPE_WEBHOOK_SECRET se rechaza todo (nunca se acepta «porque no hay secreto»)', function () {
    config(['services.stripe.webhook_secret' => null]);

    $this->stripeWebhook('payment_intent.succeeded', ['id' => 'pi_x'])->assertStatus(500)->assertJsonPath('code', 'webhook_not_configured');
});

test('durante una rotación valen el secreto nuevo y el viejo', function () {
    config(['services.stripe.webhook_secret' => 'whsec_nuevo,whsec_viejo']);
    $obj = ['id' => 'pi_zzz', 'object' => 'payment_intent', 'status' => 'succeeded'];

    $this->stripeWebhook('payment_intent.succeeded', $obj, over: ['secret' => 'whsec_viejo'])->assertOk();
    $this->stripeWebhook('payment_intent.succeeded', $obj, over: ['secret' => 'whsec_nuevo'])->assertOk();
    $this->stripeWebhook('payment_intent.succeeded', $obj, over: ['secret' => 'whsec_robado'])->assertStatus(401);
});

test('un evento que no es de ningún cobro nuestro, o de un tipo que no usamos, se acepta y se ignora', function () {
    $this->stripeWebhook('payment_intent.succeeded', ['id' => 'pi_ajeno', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 5, 'currency' => 'usd', 'customer' => 'cus_ajeno'])->assertOk();
    $this->stripeWebhook('customer.created', ['id' => 'cus_1', 'object' => 'customer'])->assertOk();

    expect(InboundWebhookReceipt::query()->pluck('status')->unique()->all())->toBe(['processed']);
    expect(PaymentDiscrepancy::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('un reembolso hecho en Stripe sobre una factura pagada se registra (parcial acumulado, total) y queda para revisión humana', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $charge = fn (int $reembolsado) => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_2', 'amount_refunded' => $reembolsado, 'refunds' => ['data' => [['id' => 're_77']]]];

    $this->stripeWebhook('charge.refunded', $charge(1000))->assertOk();
    $this->ctx->run($this->a, fn () => expect(Payment::query()->sole()->refunded_amount_minor)->toBe(1000)->and(Payment::query()->sole()->status->value)->toBe('succeeded'));

    // Un evento viejo (importe menor) no rebaja lo ya registrado; el total sí cierra el pago como reembolsado.
    $this->stripeWebhook('charge.refunded', $charge(500))->assertOk();
    $this->stripeWebhook('charge.refunded', $charge(2900))->assertOk();

    $this->ctx->run($this->a, function () {
        $p = Payment::query()->sole();
        expect($p->refunded_amount_minor)->toBe(2900)->and($p->status->value)->toBe('refunded')->and($p->refund_id)->toBe('re_77')
            ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Paid)   // la factura y la suscripción no se tocan solas
            ->and(PaymentDiscrepancy::query()->where('kind', 'refunded_externally')->count())->toBe(2);
    });
});

test('el reembolso que hace APYGG por una factura anulada, al volver por webhook, no genera discrepancia', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $this->ctx->run($this->a, function () {
        Invoice::query()->update(['status' => 'void', 'voided_at' => now()]);
        expect(app(PaymentProcessorInterface::class)->refund(Payment::query()->sole()->id))->toBeTrue();
    });
    expect($this->stripe->refunds)->toHaveCount(1)->and($this->stripe->refunds[0]['payment_intent'])->toBe('pi_2');

    $this->stripeWebhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_2', 'amount_refunded' => 2900, 'refunds' => ['data' => [['id' => 're_1']]]])->assertOk();

    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(0)->and(Payment::query()->sole()->refunded_amount_minor)->toBe(2900));
});

test('una disputa marca el pago, deja auditoría y una discrepancia abierta, sin tomar decisiones solas', function () {
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $disputa = ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_2', 'charge' => 'ch_1', 'reason' => 'fraudulent', 'amount' => 2900];

    $this->stripeWebhook('charge.dispute.created', $disputa)->assertOk();
    $this->stripeWebhook('charge.dispute.created', $disputa)->assertOk();   // otro evento, misma disputa

    $this->ctx->run($this->a, function () {
        expect(Payment::query()->sole()->dispute_id)->toBe('dp_1')
            ->and(PaymentDiscrepancy::query()->where('kind', 'dispute')->count())->toBe(1)
            ->and(AuditLog::query()->where('event', 'billing.payment-disputed')->count())->toBe(1)
            ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active);
    });
});

test('un reembolso que llega antes que el éxito del cobro falla para reintentarse y se aplica cuando el cobro ya está reflejado', function () {
    $this->stripe->loseNext();
    expect(sfAlta($this, 'pm_card_ok'))->toThrow(sfCodigo('payment_pending'));

    config(['queue.default' => 'sync']);

    try {
        $this->stripeWebhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_2', 'amount_refunded' => 100, 'metadata' => ['idempotency_key' => $this->stripePayment($this->a)->idempotency_key]], 'evt_ref');
    } catch (Throwable) {
    }
    expect(InboundWebhookReceipt::query()->where('delivery_id', 'evt_ref')->value('status'))->toBe('failed');

    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'))->assertOk();
    $recibo = InboundWebhookReceipt::query()->where('delivery_id', 'evt_ref')->firstOrFail();
    (new ProcessInboundWebhookJob($recibo->id))->handle();

    expect($recibo->refresh()->status)->toBe('processed')->and($this->stripePayment($this->a)->refunded_amount_minor)->toBe(100);
});

test('el tenant B no ve ni se ve afectado por un evento del cobro del tenant A', function () {
    $this->stripe->loseNext();
    expect(sfAlta($this, 'pm_card_ok'))->toThrow(sfCodigo('payment_pending'));
    $this->publishPlan('enterprise');
    $subB = $this->subscribeTo($this->b, $this->ownerB, 'enterprise', token: 'pm_card_ok');

    $this->stripeWebhook('payment_intent.succeeded', $this->stripe->intent('pi_2'))->assertOk();

    expect(InboundWebhookReceipt::query()->where('provider', 'stripe')->value('tenant_id'))->toBe($this->a->id);
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active));
    $this->ctx->run($this->b, fn () => expect(Subscription::query()->sole()->id)->toBe($subB->id)->and(Payment::query()->count())->toBe(1));
});

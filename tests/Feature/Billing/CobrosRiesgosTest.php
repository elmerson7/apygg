<?php

use App\Contracts\InboundWebhookServiceInterface;
use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Exceptions\BillingException;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeResult;
use App\Integrations\Payments\FakePaymentGateway;
use App\Jobs\ProcessInboundWebhookJob;
use App\Models\InboundWebhookReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\PaymentOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithStripe;
use Tests\Support\InteractsWithTenancy;

/*
 * Riesgos de cobro cerrados en la ola 6.3-A (docs/billing.md): prefijo de claves por instalación, recibos de webhook
 * fallidos que el barrido reencola, conciliación truncada, comparación del PaymentIntent BAJO bloqueo, clave de reembolso con
 * importe y 404 propio de una discrepancia inexistente. Las carreras reales con procesos están en tests/Concurrency.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class, InteractsWithStripe::class);

beforeEach(function () {
    $this->bootBilling();
    $this->owner = $this->isoUser($this->a, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
});

/** Renovación vencida cuyo cobro quedó `pending` (respuesta perdida). @return Payment el intento pendiente */
function crPendiente($test): Payment
{
    $sub = $test->subscribeTo($test->a, $test->owner);
    $test->travelTo($sub->current_period_end->copy()->addMinute());
    $test->gateway()->failNext(TransientIntegrationError::class);
    expect($test->renewNow($test->a, $sub->id)->value)->toBe('retrying');

    return $test->ctx->run($test->a, fn () => Payment::query()->where('status', 'pending')->firstOrFail());
}

function crCobro(Payment $p, string $id, bool $ok = true, bool $processing = false): ChargeResult
{
    return new ChargeResult($ok, $id, $ok || $processing ? null : 'card_declined', processing: $processing, customerId: null, amountMinor: $p->amount->amountMinor, currency: $p->currency, idempotencyKey: $p->idempotency_key);
}

// --- 1. Prefijo de claves por instalación -----------------------------------------------------------------------------------

test('con prefijo de instalación, TODAS las claves que salen a Stripe llevan el prefijo y la metadata conserva la clave local', function () {
    config(['services.stripe.key_prefix' => 'acme-prod']);
    $this->useStripe();
    $sub = $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');

    $posts = collect($this->stripe->requests)->where('method', 'POST');
    $local = $this->stripePayment($this->a)->idempotency_key;

    expect($posts->pluck('key')->filter()->every(fn ($k) => str_starts_with($k, 'acme-prod-')))->toBeTrue(json_encode($posts->pluck('key')))
        ->and($posts->where('path', '/payment_intents')->first()['key'])->toBe('acme-prod-'.$local)
        ->and($posts->where('path', '/payment_intents')->first()['body']['metadata']['idempotency_key'])->toBe($local)   // la consulta por clave sigue buscando la local
        ->and($posts->where('path', '/customers')->first()['key'])->toBe('acme-prod-customer-tenant-'.$this->a->id);
});

test('dos instalaciones con la misma cuenta de Stripe NO comparten claves de idempotencia', function () {
    config(['services.stripe.key_prefix' => 'staging']);
    $this->useStripe();
    $this->subscribeTo($this->a, $this->owner, token: 'pm_card_ok');
    $staging = collect($this->stripe->requests)->where('method', 'POST')->pluck('key')->filter()->values()->all();

    config(['services.stripe.key_prefix' => 'prod']);
    $this->useStripe();
    $this->subscribeTo($this->b, $this->isoUser($this->b, 'owner'), token: 'pm_card_ok');
    $prod = collect($this->stripe->requests)->where('method', 'POST')->pluck('key')->filter()->values()->all();

    expect(array_intersect($staging, $prod))->toBe([])
        ->and(collect($staging)->every(fn ($k) => str_starts_with($k, 'staging-')))->toBeTrue()
        ->and(collect($prod)->every(fn ($k) => str_starts_with($k, 'prod-')))->toBeTrue();
});

test('findCharge de Stripe sin cliente no lista los cobros de toda la cuenta: responde que no hay ninguno sin llamar a la red', function () {
    $this->useStripe();

    expect(app(PaymentGatewayInterface::class)->findCharge(null, 'inv-1-a1', 0))->toBeNull()
        ->and($this->stripe->requests)->toBe([]);
});

// --- 2. Recibos de webhook en `failed` ----------------------------------------------------------------------------------------

function crRecibo(string $estado, int $intentos, int $minutos, ?string $error = 'TransientIntegrationError: x'): InboundWebhookReceipt
{
    static $n = 0;
    $r = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'f-'.++$n, 'payload' => ['type' => 'x'], 'status' => $estado, 'attempts' => $intentos, 'error' => $error]);
    $r->forceFill(['updated_at' => now()->subMinutes($minutos), 'created_at' => now()->subDays(1)])->save();

    return $r;
}

test('el barrido reencola los recibos fallidos con backoff creciente, no los permanentes ni los que agotaron los intentos', function () {
    Queue::fake();
    $toca = crRecibo('failed', 5, 90);                   // 1.ª espera: 60 min
    crRecibo('failed', 5, 20);                           // todavía no
    crRecibo('failed', 7, 180);                          // 2 intentos extra: espera 240 min, aún no
    $tocaTarde = crRecibo('failed', 7, 300);             // ya
    crRecibo('failed', 5, 900, 'PermanentIntegrationError: handler inválido');   // permanente
    crRecibo('failed', 20, 900);                         // agotó max_attempts

    $res = app(InboundWebhookServiceInterface::class)->sweep();

    Queue::assertPushed(ProcessInboundWebhookJob::class, 2);
    expect($res['redispatched'])->toBe(2)->and($toca->id)->not->toBe($tocaTarde->id);
});

test('un reembolso que llega antes que el cobro reflejado falla, el recibo queda failed y el barrido lo aplica cuando el cobro ya existe', function () {
    $pago = crPendiente($this);
    $receipt = InboundWebhookReceipt::create([
        'provider' => 'stripe', 'delivery_id' => 'evt_refund_early', 'tenant_id' => $this->a->id, 'status' => 'received',
        'payload' => ['id' => 'evt_refund_early', 'type' => 'charge.refunded', 'data' => ['object' => [
            'object' => 'charge', 'payment_intent' => 'fake_pay_early', 'amount_refunded' => $pago->amount->amountMinor, 'currency' => 'usd',
            'metadata' => ['idempotency_key' => $pago->idempotency_key], 'refunds' => ['data' => [['id' => 're_early']]],
        ]]],
    ]);

    try {
        ProcessInboundWebhookJob::dispatchSync($receipt->id);
    } catch (Throwable) {
        // el job relanza para que la cola reintente
    }

    expect($receipt->refresh()->status)->toBe('failed');

    // El cobro llega (webhook de éxito o conciliación) y pasan las horas del backoff.
    $this->ctx->run($this->a, fn () => app(PaymentReconcilerInterface::class)->applyCharge(crCobro($pago, 'fake_pay_early')));
    $this->travelTo(now()->addHours(2));

    app(InboundWebhookServiceInterface::class)->sweep();

    expect($receipt->refresh()->status)->toBe('processed');
    $this->ctx->run($this->a, fn () => expect(Payment::query()->findOrFail($pago->id)->status->value)->toBe('refunded'));
});

// --- 3. Conciliación truncada --------------------------------------------------------------------------------------------------

test('la conciliación que se queda sin páginas lo cuenta como list_truncated en vez de cortar en silencio', function () {
    config(['billing.reconcile.max_pages' => 2]);
    $infinita = new class extends FakePaymentGateway
    {
        public function recentCharges(int $sinceTimestamp, ?string $cursor = null, int $limit = 100): array
        {
            return ['items' => [], 'next' => 'cursor-'.random_int(1, 999999)];
        }
    };
    app()->instance(PaymentGatewayInterface::class, $infinita);

    $counts = app(PaymentReconcilerInterface::class)->reconcile();

    expect($counts)->toHaveKey('list_truncated')->and($counts['list_truncated'])->toBe(1);
});

test('la conciliación que recorre toda la lista no marca list_truncated', function () {
    $counts = app(PaymentReconcilerInterface::class)->reconcile();

    expect($counts)->not->toHaveKey('list_truncated');
});

// --- 4. PaymentIntent comparado BAJO bloqueo -----------------------------------------------------------------------------------

test('un resultado de otro PaymentIntent sobre un intento que ya tiene el suyo no lo pisa ni salda la factura: queda como duplicate_charge', function () {
    $pago = crPendiente($this);
    $proc = app(PaymentProcessorInterface::class);

    // El intento ya tiene PaymentIntent A (p. ej. `processing`)...
    $this->ctx->run($this->a, fn () => expect($proc->applyGatewayResult($pago->id, crCobro($pago, 'pi_A', ok: false, processing: true)))->toBe(PaymentOutcome::Pending));

    // ...y llega un éxito de OTRO PaymentIntent B con la misma clave (la lectura previa del reconciliador pudo estar vieja).
    $outcome = $this->ctx->run($this->a, fn () => $proc->applyGatewayResult($pago->id, crCobro($pago, 'pi_B')));

    expect($outcome)->toBe(PaymentOutcome::OtherIntent);
    $this->ctx->run($this->a, function () use ($pago) {
        $p = Payment::query()->findOrFail($pago->id);
        expect($p->gateway_payment_id)->toBe('pi_A')->and($p->status->value)->toBe('pending')
            ->and(Invoice::query()->findOrFail($pago->invoice_id)->status->value)->toBe('open')
            ->and(PaymentDiscrepancy::query()->where('kind', 'duplicate_charge')->where('reference', 'pi_B')->count())->toBe(1);
    });
});

test('un éxito de otro PaymentIntent sobre un pago ya cobrado se registra como doble cobro aunque otro proceso lo acabe de cerrar', function () {
    $pago = crPendiente($this);
    $proc = app(PaymentProcessorInterface::class);

    $this->ctx->run($this->a, fn () => expect($proc->applyGatewayResult($pago->id, crCobro($pago, 'pi_A')))->toBe(PaymentOutcome::Paid));
    $outcome = $this->ctx->run($this->a, fn () => $proc->applyGatewayResult($pago->id, crCobro($pago, 'pi_B')));

    expect($outcome)->toBe(PaymentOutcome::OtherIntent);
    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->where('kind', 'duplicate_charge')->where('reference', 'pi_B')->count())->toBe(1)
        ->and(Payment::query()->findOrFail($pago->id)->gateway_payment_id)->toBe('pi_A'));

    // El mismo PaymentIntent otra vez es idempotente y no genera nada.
    $this->ctx->run($this->a, fn () => expect($proc->applyGatewayResult($pago->id, crCobro($pago, 'pi_A')))->toBe(PaymentOutcome::Paid));
    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(1));
});

// --- 5. Clave del reembolso con el importe -------------------------------------------------------------------------------------

test('la clave del reembolso lleva el importe cuando ya se devolvió una parte desde la pasarela: nunca la misma clave con otro importe', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $owner = $this->owner;
    $a = $this->a;
    $gateway = $this->gateway();
    $gateway->onCharge(function () use ($a, $owner, $gateway) {
        $gateway->onCharge(null);
        app(SubscriptionServiceInterface::class)->cancel($a, $owner, true);
        $gateway->failNext(TransientIntegrationError::class);   // el reembolso inmediato cae
    });
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(BillingException::class);

    $pago = $this->ctx->run($this->a, fn () => Payment::query()->orderByDesc('id')->firstOrFail());
    // Entre el primer intento y el reintento alguien devuelve 10,00 desde el panel de Stripe.
    $this->ctx->run($this->a, fn () => Payment::query()->whereKey($pago->id)->update(['refunded_amount_minor' => 1000]));

    $this->ctx->clear();
    $this->artisan('billing:renew')->assertSuccessful();

    $llamadas = collect($gateway->calls('refund'))->map(fn ($c) => [$c['payload']->idempotencyKey, $c['payload']->amount->amountMinor])->all();
    expect($llamadas)->toBe([['refund-'.$pago->id, 2900], ['refund-'.$pago->id.'-1900', 1900]]);
    $this->ctx->run($this->a, fn () => expect(Payment::query()->findOrFail($pago->id)->status->value)->toBe('refunded'));
});

// --- 6. Discrepancia inexistente -----------------------------------------------------------------------------------------------

test('resolver una discrepancia que no existe responde 404 con su propio code', function () {
    try {
        app(PaymentDiscrepancyServiceInterface::class)->resolve(999999, $this->isoUser($this->a, 'owner'), 'nota');
        $this->fail('debía lanzar');
    } catch (BillingException $e) {
        expect($e->errorCode())->toBe('discrepancy_not_found')->and($e->getStatusCode())->toBe(404);
    }
});

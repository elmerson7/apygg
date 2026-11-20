<?php

use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeRequest;
use App\Integrations\Payments\RefundRequest;
use App\Integrations\Payments\StripePaymentGateway;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function stripeGw(?string $secret = 'sk_test_abcdefghijkl'): StripePaymentGateway
{
    return new StripePaymentGateway(['secret' => $secret, 'api_base' => 'https://api.stripe.test/v1', 'api_version' => '2025-09-30.clover']);
}

function stripeCharge(bool $present = false): ChargeRequest
{
    return new ChargeRequest('cus_1', 'pm_1', Money::of(2900, 'USD'), 'inv-7-a1', 'Factura F-1', ['invoice_id' => '7', 'tenant_id' => '3'], $present);
}

function stripePi(array $over = []): array
{
    return $over + ['id' => 'pi_1', 'status' => 'succeeded', 'amount' => 2900, 'currency' => 'usd', 'customer' => 'cus_1', 'metadata' => ['idempotency_key' => 'inv-7-a1', 'tenant_id' => '3']];
}

test('un cobro envía la clave de idempotencia, el importe en unidad menor y off_session si no hay cliente delante', function () {
    Http::fake(['*' => Http::response(stripePi())]);

    $r = stripeGw()->charge(stripeCharge());

    expect($r->succeeded)->toBeTrue()->and($r->paymentId)->toBe('pi_1')->and($r->amountMinor)->toBe(2900)->and($r->currency)->toBe('USD')
        ->and($r->idempotencyKey)->toBe('inv-7-a1');

    Http::assertSent(function (Request $q) {
        parse_str($q->body(), $b);

        return $q->url() === 'https://api.stripe.test/v1/payment_intents'
            && $q->header('Idempotency-Key') === ['inv-7-a1']
            && $q->header('Authorization') === ['Bearer sk_test_abcdefghijkl']
            && $q->header('Stripe-Version') === ['2025-09-30.clover']
            && $b['amount'] === '2900' && $b['currency'] === 'usd' && $b['confirm'] === 'true' && $b['off_session'] === 'true'
            && $b['metadata']['idempotency_key'] === 'inv-7-a1' && $b['metadata']['invoice_id'] === '7';
    });
});

test('con el cliente presente no se envía off_session y un 3DS vuelve como requiresAction con el client_secret', function () {
    Http::fake(['*' => Http::response(stripePi(['status' => 'requires_action', 'client_secret' => 'pi_1_secret_zzz']))]);

    $r = stripeGw()->charge(stripeCharge(present: true));

    expect($r->succeeded)->toBeFalse()->and($r->requiresAction)->toBeTrue()->and($r->clientSecret)->toBe('pi_1_secret_zzz');
    Http::assertSent(fn (Request $q) => ! str_contains($q->body(), 'off_session'));
});

test('un rechazo del banco (402 card_error) es un resultado y no una excepción, con el decline_code', function () {
    Http::fake(['*' => Http::response(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Fondos insuficientes', 'payment_intent' => stripePi(['id' => 'pi_9', 'status' => 'requires_payment_method'])]], 402)]);

    $r = stripeGw()->charge(stripeCharge());

    expect($r->succeeded)->toBeFalse()->and($r->failureCode)->toBe('insufficient_funds')->and($r->paymentId)->toBe('pi_9')->and($r->idempotencyKey)->toBe('inv-7-a1');
});

test('cada código HTTP de infraestructura se traduce al error tipado que corresponde', function (int $status, string $class) {
    Http::fake(['*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'SECRETO-DEL-CUERPO']], $status, ['Retry-After' => '7'])]);

    try {
        stripeGw()->charge(stripeCharge());
        $this->fail('debía lanzar');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)->and($e->getMessage())->not->toContain('SECRETO-DEL-CUERPO')->and($e->getMessage())->not->toContain('sk_test');
    }
})->with([
    'rate limit' => [429, RateLimitedError::class],
    'servidor' => [500, TransientIntegrationError::class],
    'bad gateway' => [502, TransientIntegrationError::class],
    'clave en uso' => [409, TransientIntegrationError::class],
    'petición inválida' => [400, PermanentIntegrationError::class],
    'credenciales' => [401, PermanentIntegrationError::class],
]);

test('un 402 que no es de tarjeta es permanente y una caída de red es transitoria', function () {
    Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'x']], 402)]);
    expect(fn () => stripeGw()->charge(stripeCharge()))->toThrow(PermanentIntegrationError::class);

    Http::fake(fn () => throw new ConnectionException('timeout'));
    expect(fn () => stripeGw()->charge(stripeCharge()))->toThrow(TransientIntegrationError::class);
});

test('reutilizar la clave con otros parámetros es un error permanente claro', function () {
    Http::fake(['*' => Http::response(['error' => ['type' => 'idempotency_error', 'message' => 'Keys for idempotent requests...']], 400)]);

    expect(fn () => stripeGw()->charge(stripeCharge()))->toThrow(PermanentIntegrationError::class, 'idempotencia');
});

test('sin STRIPE_SECRET falla claro y sin llamar a la red', function () {
    Http::fake();

    expect(fn () => stripeGw(null)->charge(stripeCharge()))->toThrow(PermanentIntegrationError::class, 'STRIPE_SECRET');
    Http::assertNothingSent();
});

test('el PaymentIntent en processing, cancelado o en un estado raro se interpreta', function () {
    Http::fake(['*' => Http::sequence()
        ->push(stripePi(['status' => 'processing']))
        ->push(stripePi(['status' => 'canceled']))
        ->push(stripePi(['status' => 'requires_confirmation']))]);

    $gw = stripeGw();
    expect($gw->retrieve('pi_1')->processing)->toBeTrue()
        ->and($gw->retrieve('pi_1')->failureCode)->toBe('canceled');
    expect(fn () => $gw->retrieve('pi_1'))->toThrow(InvalidOutputError::class);
});

test('adjuntar un método de pago exige un pm_ y devuelve marca y últimos 4', function () {
    Http::fake(['*' => Http::response(['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242']])]);

    $m = stripeGw()->attachPaymentMethod('cus_1', 'pm_1');
    expect($m->id)->toBe('pm_1')->and($m->brand)->toBe('visa')->and($m->last4)->toBe('4242');
    Http::assertSent(fn (Request $q) => $q->url() === 'https://api.stripe.test/v1/payment_methods/pm_1/attach');

    expect(fn () => stripeGw()->attachPaymentMethod('cus_1', 'tok_visa'))->toThrow(PermanentIntegrationError::class);
    expect(fn () => stripeGw()->attachPaymentMethod('cus_1', '../x'))->toThrow(PermanentIntegrationError::class);
});

test('un cliente se crea con su clave de idempotencia y un reembolso con la suya', function () {
    Http::fake(['*/customers' => Http::response(['id' => 'cus_9']), '*/refunds' => Http::response(['id' => 're_1', 'status' => 'pending'])]);

    expect(stripeGw()->createCustomer('tenant-3', null, 'customer-tenant-3'))->toBe('cus_9');
    $re = stripeGw()->refund(new RefundRequest('pi_1', Money::of(2900, 'USD'), 'refund-5', 'invoice_void'));

    expect($re->refundId)->toBe('re_1')->and($re->succeeded)->toBeTrue();
    Http::assertSent(fn (Request $q) => $q->header('Idempotency-Key') === ['customer-tenant-3']);
    Http::assertSent(fn (Request $q) => $q->header('Idempotency-Key') === ['refund-5'] && str_contains($q->body(), 'payment_intent=pi_1'));
});

test('findCharge busca la clave en la metadata, pagina y distingue «no existe» de «no se pudo comprobar»', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [stripePi(['id' => 'pi_a', 'metadata' => ['idempotency_key' => 'otra']])], 'has_more' => true])
        ->push(['data' => [stripePi(['id' => 'pi_b'])], 'has_more' => false])]);

    expect(stripeGw()->findCharge('cus_1', 'inv-7-a1', 1000)?->paymentId)->toBe('pi_b');
});

test('findCharge devuelve null solo cuando recorrió la lista entera sin encontrarla', function () {
    Http::fake(['*' => Http::response(['data' => [], 'has_more' => false])]);

    expect(stripeGw()->findCharge('cus_1', 'inv-7-a1', 1000))->toBeNull();
});

test('findCharge no afirma que no existe si no pudo recorrer todas las páginas', function () {
    Http::fake(['*' => Http::response(['data' => [stripePi(['id' => 'pi_x', 'metadata' => []])], 'has_more' => true])]);

    expect(fn () => stripeGw()->findCharge('cus_1', 'inv-7-a1', 1000))->toThrow(TransientIntegrationError::class);
});

test('cancelar un cobro que ya no se puede cancelar devuelve su estado real', function () {
    Http::fake([
        '*/cancel' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state']], 400),
        '*/payment_intents/pi_1' => Http::response(stripePi()),
    ]);

    expect(stripeGw()->cancelCharge('pi_1')->succeeded)->toBeTrue();
});

test('ISK y UGX van a Stripe con dos decimales 00 y vuelven en unidad menor ISO; las de tres decimales exigen múltiplo de 10', function () {
    Http::fake(['*' => Http::response(stripePi(['amount' => 150000, 'currency' => 'isk']))]);

    $r = stripeGw()->charge(new ChargeRequest('cus_1', 'pm_1', Money::of(1500, 'ISK'), 'inv-7-a1', 'Factura F-1'));

    expect($r->amountMinor)->toBe(1500)->and($r->currency)->toBe('ISK');
    Http::assertSent(function (Request $q) {
        parse_str($q->body(), $b);

        return $b['amount'] === '150000' && $b['currency'] === 'isk';
    });

    expect(StripePaymentGateway::toStripeAmount(Money::of(5, 'UGX')))->toBe(500)
        ->and(StripePaymentGateway::fromStripeAmount(500, 'ugx'))->toBe(5)
        ->and(StripePaymentGateway::toStripeAmount(Money::of(500, 'JPY')))->toBe(500)
        ->and(StripePaymentGateway::toStripeAmount(Money::of(2900, 'EUR')))->toBe(2900)
        ->and(StripePaymentGateway::toStripeAmount(Money::of(12340, 'KWD')))->toBe(12340);
    expect(fn () => StripePaymentGateway::toStripeAmount(Money::of(12345, 'KWD')))->toThrow(PermanentIntegrationError::class);
});

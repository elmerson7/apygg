<?php

use App\Contracts\InspectsWebhookSignatureInterface as Sig;
use App\Integrations\Webhooks\StripeSignatureVerifier;
use Illuminate\Http\Request;

function stripeReq(string $body, ?string $header): Request
{
    return Request::create('/webhooks/inbound/stripe', 'POST', [], [], [], $header === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $header], $body);
}

function stripeSign(string $body, string $secret, ?int $t = null): string
{
    $t ??= time();

    return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
}

test('acepta una firma v1 válida y dentro de la ventana', function () {
    $v = new StripeSignatureVerifier;

    expect($v->inspect(stripeReq('{"a":1}', stripeSign('{"a":1}', 'whsec_abc')), 'whsec_abc'))->toBeNull();
});

test('distingue ausente, inválida y caducada, y la caducada solo con firma correcta', function () {
    $v = new StripeSignatureVerifier;
    $old = time() - 4000;

    expect($v->inspect(stripeReq('{}', null), 'whsec_abc'))->toBe(Sig::MISSING)
        ->and($v->inspect(stripeReq('{}', 't='.time().',v1='.str_repeat('0', 64)), 'whsec_abc'))->toBe(Sig::INVALID)
        ->and($v->inspect(stripeReq('{}', 'garbage'), 'whsec_abc'))->toBe(Sig::INVALID)
        ->and($v->inspect(stripeReq('{}', 'v1=abc'), 'whsec_abc'))->toBe(Sig::INVALID)
        ->and($v->inspect(stripeReq('{}', stripeSign('{}', 'whsec_abc', $old)), 'whsec_abc'))->toBe(Sig::EXPIRED)
        ->and($v->inspect(stripeReq('{}', stripeSign('{}', 'otro', $old)), 'whsec_abc'))->toBe(Sig::INVALID);
});

test('un cuerpo alterado o firmado con otro timestamp no valida', function () {
    $v = new StripeSignatureVerifier;
    $firma = stripeSign('{"a":1}', 'whsec_abc');

    expect($v->verify(stripeReq('{"a":2}', $firma), 'whsec_abc'))->toBeFalse();
    [$t, $sig] = explode(',', $firma);
    expect($v->verify(stripeReq('{"a":1}', 't='.(time() - 1).','.$sig), 'whsec_abc'))->toBeFalse();
});

test('rotación: vale cualquiera de los secretos y cualquiera de las firmas v1; los v0 se ignoran', function () {
    $v = new StripeSignatureVerifier;
    $t = time();
    $nueva = hash_hmac('sha256', "{$t}.{}", 'whsec_nuevo');
    $vieja = hash_hmac('sha256', "{$t}.{}", 'whsec_viejo');

    expect($v->verify(stripeReq('{}', "t={$t},v1={$vieja}"), 'whsec_nuevo,whsec_viejo'))->toBeTrue()
        ->and($v->verify(stripeReq('{}', "t={$t},v1={$nueva},v1={$vieja}"), 'whsec_viejo'))->toBeTrue()
        ->and($v->verify(stripeReq('{}', "t={$t},v0={$nueva}"), 'whsec_nuevo'))->toBeFalse();
});

<?php

use App\Services\Mail\SvixSignatureVerifier;
use Illuminate\Http\Request;

/**
 * El algoritmo se validó contra la librería oficial svix/svix: para el mismo
 * (id, timestamp, body, secreto) produce una firma byte a byte idéntica.
 */
$secret = 'whsec_'.base64_encode(str_repeat('k', 24));

$sign = function (string $id, string $timestamp, string $body) use ($secret): string {
    $key = base64_decode(substr($secret, 6), true);

    return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
};

$makeRequest = function (string $id, string $timestamp, string $body, string $signature): Request {
    $request = Request::create('/webhooks/mail', 'POST', [], [], [], [], $body);

    $request->headers->set('svix-id', $id);
    $request->headers->set('svix-timestamp', $timestamp);
    $request->headers->set('svix-signature', $signature);

    return $request;
};

test('acepta una firma válida', function () use ($secret, $sign, $makeRequest) {
    $id = 'msg_1';
    $ts = (string) time();
    $body = '{"type":"email.delivered"}';

    $request = $makeRequest($id, $ts, $body, $sign($id, $ts, $body));

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeTrue();
});

test('rechaza una firma que no corresponde al cuerpo', function () use ($secret, $sign, $makeRequest) {
    $id = 'msg_1';
    $ts = (string) time();

    $request = $makeRequest($id, $ts, '{"type":"email.bounced"}', $sign($id, $ts, '{"type":"email.delivered"}'));

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeFalse();
});

test('rechaza un timestamp fuera de la ventana de tolerancia (anti-replay)', function () use ($secret, $sign, $makeRequest) {
    $id = 'msg_1';
    $ts = (string) (time() - 3600);
    $body = '{"type":"email.delivered"}';

    $request = $makeRequest($id, $ts, $body, $sign($id, $ts, $body));

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeFalse();
});

test('rechaza cuando faltan cabeceras', function () use ($secret) {
    $request = Request::create('/webhooks/mail', 'POST', [], [], [], [], '{}');

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeFalse();
});

test('rechaza una firma con versión desconocida', function () use ($secret, $makeRequest) {
    $request = $makeRequest('msg_1', (string) time(), '{}', 'v2,cualquiercosa');

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeFalse();
});

test('acepta si alguna de las firmas de la lista es válida (rotación de secreto)', function () use ($secret, $sign, $makeRequest) {
    $id = 'msg_1';
    $ts = (string) time();
    $body = '{"type":"email.delivered"}';

    $header = 'v1,firmaVieja '.$sign($id, $ts, $body);
    $request = $makeRequest($id, $ts, $body, $header);

    expect((new SvixSignatureVerifier)->verify($request, $secret))->toBeTrue();
});

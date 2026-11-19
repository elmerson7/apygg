<?php

use App\Http\Middleware\ResolveClientIpMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

$run = function (Request $request): Request {
    $middleware = new ResolveClientIpMiddleware;

    $middleware->handle($request, fn (Request $request) => new Response);

    return $request;
};

test('reescribe REMOTE_ADDR cuando el peer es Cloudflare y existe CF-Connecting-IP', function () use ($run) {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '172.69.90.3');
    $request->headers->set('CF-Connecting-IP', '200.1.2.34');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('200.1.2.34')
        ->and($request->getClientIp())->toBe('200.1.2.34');
});

test('ignora CF-Connecting-IP cuando el peer NO es Cloudflare (anti-spoof)', function () use ($run) {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '203.0.113.9');
    $request->headers->set('CF-Connecting-IP', '200.1.2.34');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('203.0.113.9')
        ->and($request->getClientIp())->toBe('203.0.113.9');
});

test('no modifica REMOTE_ADDR cuando no hay CF-Connecting-IP', function () use ($run) {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '172.69.90.3');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('172.69.90.3');
});

test('ignora CF-Connecting-IP con valor inválido', function () use ($run) {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '172.69.90.3');
    $request->headers->set('CF-Connecting-IP', 'not-an-ip');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('172.69.90.3');
});

test('maneja peer IPv6 de Cloudflare', function () use ($run) {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '2606:4700:3030::6814:a70a');
    $request->headers->set('CF-Connecting-IP', '2001:db8::1');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('2001:db8::1');
});

test('respeta el interruptor de configuración', function () use ($run) {
    config()->set('security.cloudflare.enabled', false);

    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '172.69.90.3');
    $request->headers->set('CF-Connecting-IP', '200.1.2.34');

    $run($request);

    expect($request->server->get('REMOTE_ADDR'))->toBe('172.69.90.3');

    config()->set('security.cloudflare.enabled', true);
});

test('las listas de rangos Cloudflare contienen CIDRs válidos', function () {
    $reflection = new ReflectionClass(ResolveClientIpMiddleware::class);

    $ranges = array_merge(
        $reflection->getConstant('CLOUDFLARE_IPV4_RANGES'),
        $reflection->getConstant('CLOUDFLARE_IPV6_RANGES')
    );

    expect($ranges)->not->toBeEmpty();

    foreach ($ranges as $range) {
        expect(str_contains($range, '/'))->toBeTrue();
    }
});

test('no deja falsificar a Cloudflare con X-Forwarded-For cuando los proxies de confianza son *', function () use ($run) {
    Request::setTrustedProxies(['*'], Request::HEADER_X_FORWARDED_FOR);

    try {
        $request = Request::create('/users', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.9');
        // El atacante dice que viene de Cloudflare y manda la IP que quiere
        $request->headers->set('X-Forwarded-For', '173.245.48.1');
        $request->headers->set('CF-Connecting-IP', '200.1.2.34');

        $run($request);

        expect($request->server->get('REMOTE_ADDR'))->toBe('203.0.113.9')
            ->and($request->getClientIp())->not->toBe('200.1.2.34');
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});

<?php

use App\Exceptions\BusinessLogicException;
use App\Services\Webhooks\WebhookUrlGuard;

beforeEach(function () {
    $this->fakeDns(fn (string $host) => match ($host) {
        'interno.test' => ['10.1.2.3'],
        'mixto.test' => ['93.184.216.34', '127.0.0.1'],
        'nada.test' => [],
        '2130706433' => ['127.0.0.1'],
        default => ['93.184.216.34'],
    });
});

it('rechaza las urls que apuntan a la red interna', function (string $url) {
    expect(fn () => app(WebhookUrlGuard::class)->inspect($url))->toThrow(BusinessLogicException::class);
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'metadata cloud' => 'https://169.254.169.254/latest/meta-data',
    'privada 10' => 'https://10.0.0.5/hook',
    'privada 172' => 'https://172.16.0.1/hook',
    'privada 192' => 'https://192.168.1.1/hook',
    'cgnat' => 'https://100.64.0.1/hook',
    'cero' => 'https://0.0.0.0/hook',
    'ipv6 loopback' => 'https://[::1]/hook',
    'ipv6 unique local' => 'https://[fd00:ec2::254]/hook',
    'ipv6 link-local' => 'https://[fe80::1]/hook',
    'ipv4 mapeada en ipv6' => 'https://[::ffff:127.0.0.1]/hook',
    'nombre que resuelve a privada' => 'https://interno.test/hook',
    'nombre con una ip publica y otra loopback' => 'https://mixto.test/hook',
    'ip en decimal' => 'https://2130706433/hook',
    'nombre que no resuelve' => 'https://nada.test/hook',
    'esquema ftp' => 'ftp://example.com/hook',
    'http sin permitirlo' => 'http://example.com/hook',
    'credenciales en la url' => 'https://user:clave@example.com/hook',
    'sin host' => 'https:///hook',
]);

it('acepta una url https que resuelve a una ip publica', function () {
    expect(app(WebhookUrlGuard::class)->inspect('https://example.com/hook'))
        ->toMatchArray(['host' => 'example.com', 'port' => 443, 'ip' => '93.184.216.34']);
});

it('acepta una ip publica literal', function () {
    expect(app(WebhookUrlGuard::class)->inspect('https://93.184.216.34:8443/hook'))
        ->toMatchArray(['is_literal' => true, 'port' => 8443]);
});

it('permite http solo si la config lo habilita', function () {
    config(['webhooks.ssrf.allow_http' => true]);

    expect(app(WebhookUrlGuard::class)->inspect('http://example.com/hook')['port'])->toBe(80);
});

it('permite un host interno de confianza listado en la config', function () {
    config(['webhooks.ssrf.allowed_hosts' => ['interno.test']]);

    expect(app(WebhookUrlGuard::class)->inspect('https://interno.test/hook')['ip'])->toBe('10.1.2.3');
});

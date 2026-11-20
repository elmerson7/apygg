<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app-versions.enabled' => true,
        'app-versions.minimum' => ['*' => '1.0.0', 'ios' => '2.1.0', 'android' => '3.0.0'],
    ]);
});

function conApp(?string $version, ?string $platform)
{
    $headers = array_filter(['X-App-Version' => $version, 'X-App-Platform' => $platform]);

    return test()->withHeaders($headers);
}

test('una version menor que la minima de su plataforma recibe 426 app_update_required', function () {
    conApp('2.0.9', 'ios')->postJson('/auth/login', ['login' => 'a@b.c', 'password' => 'x'])
        ->assertStatus(426)
        ->assertJsonPath('code', 'app_update_required')
        ->assertJsonPath('errors.minimum_version', '2.1.0')
        ->assertJsonPath('errors.platform', 'ios');
});

test('la version minima o superior pasa', function () {
    conApp('2.1.0', 'ios')->getJson('/auth/me')->assertStatus(401);
    conApp('10.0.0', 'ios')->getJson('/auth/me')->assertStatus(401);
});

test('cada plataforma usa su minima y el resto la comodin', function () {
    conApp('2.5.0', 'android')->getJson('/auth/me')->assertStatus(426);
    conApp('0.9.0', 'web')->getJson('/auth/me')->assertStatus(426);
    conApp('1.0.0', 'web')->getJson('/auth/me')->assertStatus(401);
});

test('salud, webhooks entrantes y docs no se bloquean', function () {
    conApp('0.0.1', 'ios')->getJson('/health')->assertStatus(200);
    conApp('0.0.1', 'ios')->getJson('/health/live')->assertStatus(200);
    expect(conApp('0.0.1', 'ios')->postJson('/webhooks/inbound/ninguno', [])->status())->not->toBe(426);
});

test('sin cabecera pasa salvo que se exija', function () {
    conApp(null, null)->getJson('/auth/me')->assertStatus(401);

    config(['app-versions.require_header' => true]);
    conApp(null, null)->getJson('/auth/me')->assertStatus(426);
});

test('apagado por defecto no bloquea a nadie', function () {
    config(['app-versions.enabled' => false]);

    conApp('0.0.1', 'ios')->getJson('/auth/me')->assertStatus(401);
});

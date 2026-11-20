<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Contracts\SettingsRegistryInterface;
use App\Support\Tenancy\Exceptions\MissingTenantContext;

beforeEach(function () {
    $this->bootIsolation();
    $this->adminA = $this->isoUser($this->a, 'admin', ['tenant-settings.read', 'tenant-settings.manage']);
    $this->adminB = $this->isoUser($this->b, 'admin', ['tenant-settings.read', 'tenant-settings.manage']);
    $this->valor = fn ($res, string $key) => collect($res->json('data'))->firstWhere('key', $key);
});

test('un ajuste escrito en A no cambia lo que ve B y B restablecer no toca el de A', function () {
    $this->isoCall($this->isoHeaders($this->adminA, $this->a), 'PUT', '/tenant/settings/general.theme', ['value' => 'dark'])->assertOk();

    $deA = ($this->valor)($this->isoCall($this->isoHeaders($this->adminA, $this->a), 'GET', '/tenant/settings')->assertOk(), 'general.theme');
    $deB = ($this->valor)($this->isoCall($this->isoHeaders($this->adminB, $this->b), 'GET', '/tenant/settings')->assertOk(), 'general.theme');
    expect($deA['value'])->toBe('dark')->and($deB['value'])->not->toBe('dark');

    $this->isoCall($this->isoHeaders($this->adminB, $this->b), 'DELETE', '/tenant/settings/general.theme')->assertOk();
    $deA = ($this->valor)($this->isoCall($this->isoHeaders($this->adminA, $this->a), 'GET', '/tenant/settings'), 'general.theme');
    expect($deA['value'])->toBe('dark');
});

test('escribir ajustes de A con el cuerpo trayendo el tenant de B solo cambia A', function () {
    $this->isoCall($this->isoHeaders($this->adminA, $this->a), 'PUT', '/tenant/settings/general.theme', ['value' => 'dark', 'tenant_id' => $this->b->id]);

    expect($this->a->refresh()->settings)->toHaveKey('general.theme')
        ->and($this->b->refresh()->settings ?? [])->not->toHaveKey('general.theme');
});

test('el admin de A no escribe ni lee ajustes de B declarando X-Tenant de B', function () {
    $h = $this->isoHeaders($this->adminA, $this->b);

    $this->isoCall($h, 'GET', '/tenant/settings')->assertForbidden();
    $this->isoCall($h, 'PUT', '/tenant/settings/general.theme', ['value' => 'dark'])->assertForbidden();
    expect($this->b->refresh()->settings ?? [])->not->toHaveKey('general.theme');
});

test('el registro de ajustes resuelve por el tenant del contexto y exige contexto para escribir', function () {
    $registry = app(SettingsRegistryInterface::class);
    $this->ctx->run($this->a, fn () => $registry->setTenant($this->adminA, 'general.theme', 'dark'));

    $this->ctx->run($this->a, fn () => expect($registry->get('general.theme'))->toBe('dark'));
    $this->ctx->run($this->b, fn () => expect($registry->get('general.theme'))->not->toBe('dark'));

    $this->ctx->clear();
    expect(fn () => $registry->setTenant($this->adminA, 'general.theme', 'light'))->toThrow(MissingTenantContext::class);
});

test('el idioma, la zona horaria y la moneda de A no cambian los de B', function () {
    $this->isoCall($this->isoHeaders($this->adminA, $this->a), 'PUT', '/tenant/locale', ['locale' => 'en', 'timezone' => 'America/Bogota', 'currency' => 'COP', 'tenant_id' => $this->b->id])->assertOk();

    expect($this->a->refresh()->currency)->toBe('COP')
        ->and($this->b->refresh()->currency)->toBeNull()
        ->and($this->b->locale)->toBeNull();

    $this->isoCall($this->isoHeaders($this->adminB, $this->b), 'GET', '/tenant/locale')->assertOk()->assertJsonPath('data.stored.currency', null);
    $this->isoCall($this->isoHeaders($this->adminA, $this->b), 'PUT', '/tenant/locale', ['currency' => 'EUR'])->assertForbidden();
    expect($this->b->refresh()->currency)->toBeNull();
});

<?php

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\PermissionTeamsSchema;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithTenancy;

/*
 * Contrato de TenantContext (tenant activo de la petición, job o comando): valores por defecto, set/clear,
 * require, run anidado, withoutTenant, enforcing y el equipo de permisos de Spatie que sigue al tenant.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->enableTenancy();
    $this->ctx = new TenantContext;
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
});

test('recién creado no tiene tenant, no está en modo plataforma y aplica el aislamiento', function () {
    expect($this->ctx->current())->toBeNull()
        ->and($this->ctx->id())->toBeNull()
        ->and($this->ctx->isWithoutTenant())->toBeFalse()
        ->and($this->ctx->enforcing())->toBeTrue();
});

test('sin la clave de configuración la multiempresa se considera apagada', function () {
    config(['multi-tenancy' => []]);

    expect($this->ctx->enabled())->toBeFalse()
        ->and($this->ctx->enforcing())->toBeFalse();
});

test('con multiempresa apagado no aplica el aislamiento aunque no esté en modo plataforma', function () {
    config(['multi-tenancy.enabled' => false]);

    expect($this->ctx->isWithoutTenant())->toBeFalse()
        ->and($this->ctx->enforcing())->toBeFalse();
});

test('set fija el tenant actual, su id entero y el tenant oculto del Context para los jobs', function () {
    $conClaveDeTexto = (new Tenant)->newFromBuilder(['id' => (string) $this->a->id, 'name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

    $this->ctx->set($conClaveDeTexto);

    expect($this->ctx->current())->toBe($conClaveDeTexto)
        ->and($this->ctx->require())->toBe($conClaveDeTexto)
        ->and($this->ctx->id())->toBe($this->a->id)
        ->and($this->ctx->requireId('Modelo', 'read'))->toBe($this->a->id)
        ->and(Context::getHidden(TenantContext::CONTEXT_KEY))->toBe($this->a->id);
});

test('require y requireId fallan cerrados sin tenant', function () {
    expect(fn () => $this->ctx->require())->toThrow(MissingTenantContext::class)
        ->and(fn () => $this->ctx->requireId('Modelo', 'read'))->toThrow(MissingTenantContext::class);
});

test('clear quita el tenant, el modo plataforma y el tenant oculto del Context', function () {
    $this->ctx->set($this->a);

    $this->ctx->withoutTenant(function () {
        $this->ctx->clear();

        expect($this->ctx->isWithoutTenant())->toBeFalse();
    });

    expect($this->ctx->current())->toBeNull()
        ->and(Context::hasHidden(TenantContext::CONTEXT_KEY))->toBeFalse();
});

test('con equipos de Spatie el equipo de permisos sigue al tenant y vuelve a la plataforma al limpiar', function () {
    config(['permission.teams' => true]);
    $registrar = app(PermissionRegistrar::class);
    $conClaveDeTexto = (new Tenant)->newFromBuilder(['id' => (string) $this->b->id, 'name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);

    $this->ctx->set($conClaveDeTexto);
    expect($registrar->getPermissionsTeamId())->toBe($this->b->id);

    $this->ctx->clear();
    expect($registrar->getPermissionsTeamId())->toBe(PermissionTeamsSchema::PLATFORM_TEAM);
});

test('sin equipos de Spatie el equipo de permisos no se toca', function () {
    config(['permission.teams' => false]);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);

    $this->ctx->set($this->a);

    expect($registrar->getPermissionsTeamId())->toBeNull();
});

test('run anidado restaura el tenant anterior al terminar, también si el callback lanza', function () {
    $this->ctx->run($this->a, function () {
        $dentro = $this->ctx->run($this->b, fn () => $this->ctx->id());

        expect($dentro)->toBe($this->b->id)
            ->and($this->ctx->id())->toBe($this->a->id)
            ->and(Context::getHidden(TenantContext::CONTEXT_KEY))->toBe($this->a->id);

        expect(fn () => $this->ctx->run($this->b, fn () => throw new RuntimeException('fallo')))->toThrow(RuntimeException::class);

        expect($this->ctx->id())->toBe($this->a->id);
    });

    expect($this->ctx->current())->toBeNull();
});

test('withoutTenant activa el modo plataforma solo durante el callback', function () {
    $dentro = $this->ctx->withoutTenant(fn () => [$this->ctx->isWithoutTenant(), $this->ctx->enforcing()]);

    expect($dentro)->toBe([true, false])
        ->and($this->ctx->isWithoutTenant())->toBeFalse()
        ->and($this->ctx->enforcing())->toBeTrue();
});

test('run dentro de withoutTenant aplica el aislamiento y al salir restaura el modo plataforma', function () {
    $this->ctx->withoutTenant(function () {
        $dentro = $this->ctx->run($this->a, fn () => $this->ctx->enforcing());

        expect($dentro)->toBeTrue()
            ->and($this->ctx->isWithoutTenant())->toBeTrue();
    });
});

<?php

use App\Models\User;
use App\Rules\TenantExists;
use App\Rules\TenantUnique;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use App\Traits\Searchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

class NotaBuscable extends TenantNote
{
    use Searchable;

    protected $table = 'tenant_notes';
}

beforeEach(function () {
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);
});

// ── Búsqueda ────────────────────────────────────────────────────────────────

test('el indice de busqueda de un modelo de tenant lleva tenant_id y es filtrable', function () {
    $nota = $this->ctx->run($this->a, fn () => NotaBuscable::create(['title' => 'x']));

    expect($nota->toSearchableArray())->toHaveKey('tenant_id', $this->a->id)
        ->and($nota->getFilterableAttributes())->toContain('tenant_id');
});

test('search filtra por el tenant del contexto', function () {
    $builder = $this->ctx->run($this->a, fn () => NotaBuscable::search('x'));

    expect($builder->wheres)->toBe(['tenant_id' => $this->a->id]);
});

test('search sin tenant en el contexto falla cerrado y dentro de withoutTenant no filtra', function () {
    expect(fn () => NotaBuscable::search('x'))->toThrow(MissingTenantContext::class);

    expect(TenantQuery::withoutTenant(fn () => NotaBuscable::search('x')->wheres, 'prueba'))->toBe([]);
});

test('un modelo sin tenant (User) sigue buscando sin filtro de tenant', function () {
    expect($this->ctx->run($this->a, fn () => User::search('x')->wheres))->toBe([]);
});

// ── exists / unique por tenant ──────────────────────────────────────────────

test('TenantExists no confirma ids de otro tenant', function () {
    $deB = $this->ctx->run($this->b, fn () => TenantNote::create(['title' => 'secreta']));
    $deA = $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'mia']));

    $valida = fn (int $id) => $this->ctx->run($this->a, fn () => Validator::make(['n' => $id], ['n' => [new TenantExists('tenant_notes', 'id')]])->passes());

    expect($valida($deA->id))->toBeTrue()
        ->and($valida($deB->id))->toBeFalse();
});

test('TenantUnique permite repetir un valor que usa otro tenant pero no el propio', function () {
    $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'repetido']));
    $this->ctx->run($this->b, fn () => TenantNote::create(['title' => 'solo-de-b']));

    $pasa = fn ($tenant, string $titulo) => $this->ctx->run($tenant, fn () => Validator::make(['t' => $titulo], ['t' => [new TenantUnique('tenant_notes', 'title')]])->passes());

    expect($pasa($this->a, 'repetido'))->toBeFalse()
        ->and($pasa($this->a, 'solo-de-b'))->toBeTrue()
        ->and($pasa($this->b, 'repetido'))->toBeTrue();
});

test('las reglas de tenant fallan cerrado sin tenant en el contexto', function () {
    expect(fn () => Validator::make(['n' => 1], ['n' => [new TenantExists('tenant_notes')]])->passes())->toThrow(MissingTenantContext::class);
    expect(fn () => Validator::make(['title' => 'x'], ['title' => [new TenantUnique('tenant_notes')]])->passes())->toThrow(MissingTenantContext::class);
});

test('TenantUnique ignora la propia fila al actualizar', function () {
    $nota = $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'mia']));

    $pasa = $this->ctx->run($this->a, fn () => Validator::make(['title' => 'mia'], ['title' => [(new TenantUnique('tenant_notes'))->ignore($nota->id)]])->passes());

    expect($pasa)->toBeTrue();
});

test('con multiempresa apagado las reglas se comportan como exists y unique normales', function () {
    config(['multi-tenancy.enabled' => false]);

    expect(Validator::make(['u' => 999999], ['u' => [new TenantExists('users', 'id')]])->passes())->toBeFalse();
});

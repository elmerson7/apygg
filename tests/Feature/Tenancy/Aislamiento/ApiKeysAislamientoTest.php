<?php

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $crear = fn ($tenant, $user, string $name) => $this->ctx->run($tenant, fn () => app(ApiKeyService::class)->create($user, $name, ['*'])['api_key']);
    $this->keyA = $crear($this->a, $this->userA, 'de-a');
    $this->keyB = $crear($this->b, $this->userB, 'de-b');
});

test('el listado de API keys desde A no muestra las de B', function () {
    $this->assertListIsolated($this->isoHeaders($this->userA, $this->a), '/api-keys', 'name', ['de-a'], ['de-b']);
});

test('ver, rotar y revocar una API key de B desde A responde 404', function () {
    $id = $this->keyB->id;
    $this->assertForeignIdNotFound($this->isoHeaders($this->userA, $this->a), [
        ['GET', "/api-keys/{$id}"],
        ['POST', "/api-keys/{$id}/rotate", []],
        ['DELETE', "/api-keys/{$id}"],
    ]);

    expect(TenantQuery::withoutTenant(fn () => ApiKey::find($id), 'test'))->not->toBeNull();
});

test('crear una API key desde A ignora un tenant_id de B en el cuerpo', function () {
    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->userA, $this->a),
        '/api-keys',
        ['name' => 'nueva', 'scopes' => ['profile.read']],
        fn ($res) => TenantQuery::withoutTenant(fn () => ApiKey::findOrFail($res->json('data.api_key.id'))->tenant_id, 'test'),
    );
});

test('el modelo ApiKey solo ve las filas del tenant activo y falla cerrado sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(ApiKey::query()->pluck('name')->all())->toBe(['de-a']));
    $this->ctx->run($this->b, fn () => expect(ApiKey::query()->pluck('name')->all())->toBe(['de-b']));
    $this->ctx->clear();
    expect(fn () => ApiKey::query()->count())->toThrow(MissingTenantContext::class);
});

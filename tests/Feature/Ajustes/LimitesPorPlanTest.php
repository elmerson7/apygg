<?php

use App\Contracts\PlanLimitsInterface;
use App\Exceptions\PlanLimitReachedException;
use App\Exceptions\PlanLimitUnknownException;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();
    config(['plans.enabled' => true]);
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->limits = app(PlanLimitsInterface::class);
    $this->ctx = app(TenantContext::class);
});

function plHeaders($test, $tenant, array $permisos = []): array
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());
    $test->addMember($tenant, $user);
    foreach ($permisos as $p) {
        $user->givePermissionTo($p);
    }

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $tenant->id];
}

test('el límite sale del plan, luego del override y null es ilimitado', function () {
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBe(2); // free

    $this->limits->setPlan($this->a->id, 'pro');
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBe(20)
        ->and($this->limits->limit('max_api_keys', $this->b->id))->toBe(2);

    $this->limits->setOverride($this->a->id, 'max_api_keys', 5);
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBe(5);

    $this->limits->setOverride($this->a->id, 'max_api_keys', null);
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBeNull();

    $this->limits->clearOverride($this->a->id, 'max_api_keys');
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBe(20);

    $this->limits->setPlan($this->a->id, 'enterprise');
    expect($this->limits->limit('max_members', $this->a->id))->toBeNull();
});

test('apagado o sin tenant no limita nada', function () {
    config(['plans.enabled' => false]);
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBeNull();

    config(['plans.enabled' => true]);
    expect($this->limits->limit('max_api_keys'))->toBeNull();
    expect(fn () => $this->limits->limit('clave_inventada'))->toThrow(PlanLimitUnknownException::class);
});

test('ensureWithin lanza plan_limit_reached al pasarse y check informa', function () {
    expect($this->limits->check('max_api_keys', 1, 1, $this->a->id))->toBeTrue()
        ->and($this->limits->check('max_api_keys', 2, 1, $this->a->id))->toBeFalse();

    $this->limits->ensureWithin('max_api_keys', 1, 1, $this->a->id);
    expect(fn () => $this->limits->ensureWithin('max_api_keys', 2, 1, $this->a->id))->toThrow(PlanLimitReachedException::class);
});

test('crear API keys por la API respeta el límite del plan con 403 plan_limit_reached', function () {
    $h = plHeaders($this, $this->a);

    $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k1'])->assertCreated();
    $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k2'])->assertCreated();
    $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k3'])
        ->assertForbidden()->assertJsonPath('code', 'plan_limit_reached')
        ->assertJsonPath('limit', 2);

    // El tenant B tiene su propio cupo
    $hb = plHeaders($this, $this->b);
    $this->asHeaders($hb)->postJson('/api-keys', ['name' => 'b1'])->assertCreated();

    // Subir el plan lo desbloquea
    $this->limits->setPlan($this->a->id, 'pro');
    $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k3'])->assertCreated();
});

test('rotar una key en el tope no se bloquea', function () {
    $h = plHeaders($this, $this->a);
    $id = $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k1'])->json('data.api_key.id');
    $this->asHeaders($h)->postJson('/api-keys', ['name' => 'k2'])->assertCreated();

    $this->asHeaders($h)->postJson("/api-keys/{$id}/rotate")->assertSuccessful();
});

test('guarded cuenta y crea bajo bloqueo de la fila del tenant', function () {
    $consultas = [];
    DB::listen(function ($q) use (&$consultas) {
        $consultas[] = $q->sql;
    });

    $this->ctx->run($this->a, function () {
        $this->limits->guarded('max_api_keys', fn () => ApiKey::query()->count(), fn () => 'hecho');
    });

    expect(collect($consultas)->contains(fn ($sql) => str_contains($sql, 'for update')))->toBeTrue();
});

test('la API de plataforma gestiona plan y límites y exige permiso', function () {
    $admin = ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];

    $this->asHeaders($admin)->putJson("/admin/tenants/{$this->a->id}/plan", ['plan' => 'pro'])
        ->assertOk()->assertJsonPath('data.plan', 'pro');
    $this->asHeaders($admin)->putJson("/admin/tenants/{$this->a->id}/plan", ['plan' => 'inventado'])->assertStatus(422);
    $this->asHeaders($admin)->putJson("/admin/tenants/{$this->a->id}/limits/max_api_keys", ['value' => 9])
        ->assertOk()->assertJsonPath('data.limits.max_api_keys.limit', 9);
    $this->asHeaders($admin)->deleteJson("/admin/tenants/{$this->a->id}/limits/max_api_keys")->assertOk();
    $this->asHeaders($admin)->getJson('/admin/tenants/99999/plan')->assertNotFound();

    $h = plHeaders($this, $this->a);
    unset($h['X-Tenant']);
    $this->asHeaders($h)->getJson("/admin/tenants/{$this->a->id}/plan")->assertForbidden();
});

test('el tenant lee su plan y no el de otro', function () {
    $this->limits->setPlan($this->b->id, 'pro');
    $h = plHeaders($this, $this->a, ['tenant-settings.read']);

    $this->asHeaders($h)->getJson('/tenant/limits')->assertOk()->assertJsonPath('data.plan', 'free');
    $this->asHeaders(plHeaders($this, $this->a))->getJson('/tenant/limits')->assertForbidden();
});

test('una clave de limite inexistente es una excepcion de dominio 422 con code plan_limit_unknown', function () {
    app('router')->get('/__limite-desconocido', fn () => app(PlanLimitsInterface::class)->limit('clave_inventada'));

    $this->getJson('/__limite-desconocido')->assertStatus(422)->assertJsonPath('code', 'plan_limit_unknown');
});

test('clearOverride de un tenant inexistente lanza ModelNotFoundException y no borra nada', function () {
    $this->limits->setOverride($this->a->id, 'max_api_keys', 5);

    expect(fn () => $this->limits->clearOverride(999999, 'max_api_keys'))
        ->toThrow(ModelNotFoundException::class);
    expect($this->limits->limit('max_api_keys', $this->a->id))->toBe(5);
});

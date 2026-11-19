<?php

use App\Contracts\FeatureFlagServiceInterface;
use App\Http\Middleware\CheckFeatureFlag;
use App\Models\Role;
use App\Models\User;
use App\Support\Features\Rollout;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();
    config(['features.demo' => ['enabled' => false, 'description' => 'demo']]);
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->flags = app(FeatureFlagServiceInterface::class);
});

function ffAdmin(): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];
}

test('sin fila en BD manda el config y la BD lo sobrescribe sin deploy', function () {
    expect($this->flags->enabledFor('demo'))->toBeFalse();

    $this->flags->setFlag('demo', ['enabled' => true]);
    expect($this->flags->enabledFor('demo'))->toBeTrue();

    $this->flags->resetFlag('demo');
    expect($this->flags->enabledFor('demo'))->toBeFalse()
        ->and($this->flags->enabledFor('no_declarada'))->toBeFalse();
});

test('el orden es usuario, luego tenant, luego global', function () {
    $u = User::factory()->create();
    $this->flags->setFlag('demo', ['enabled' => true]);

    $this->flags->setTenantOverride('demo', $this->a->id, false);
    expect($this->flags->enabledFor('demo', $u->id, $this->a->id))->toBeFalse()
        ->and($this->flags->enabledFor('demo', $u->id, $this->b->id))->toBeTrue();

    $this->flags->setUserOverride('demo', $u->id, true);
    expect($this->flags->enabledFor('demo', $u->id, $this->a->id))->toBeTrue();

    $this->flags->clearUserOverride('demo', $u->id);
    $this->flags->clearTenantOverride('demo', $this->a->id);
    expect($this->flags->enabledFor('demo', $u->id, $this->a->id))->toBeTrue();
});

test('el override del tenant A no afecta al tenant B', function () {
    $this->flags->setFlag('demo', ['enabled' => false]);
    $this->flags->setTenantOverride('demo', $this->a->id, true);

    $ctx = app(TenantContext::class);
    expect($ctx->run($this->a, fn () => $this->flags->enabled('demo')))->toBeTrue()
        ->and($ctx->run($this->b, fn () => $this->flags->enabled('demo')))->toBeFalse();
});

test('el porcentaje es estable y su distribución aproximada', function () {
    $this->flags->setFlag('demo', ['enabled' => true, 'rollout_percentage' => 30, 'rollout_by' => 'user']);

    $primera = collect(range(1, 1000))->map(fn ($id) => $this->flags->enabledFor('demo', $id));
    $segunda = collect(range(1, 1000))->map(fn ($id) => $this->flags->enabledFor('demo', $id));

    expect($primera->all())->toBe($segunda->all());
    expect($primera->filter()->count())->toBeBetween(250, 350);

    // Subir el porcentaje solo añade sujetos
    $this->flags->setFlag('demo', ['rollout_percentage' => 60]);
    foreach (range(1, 1000) as $id) {
        if ($primera[$id - 1]) {
            expect($this->flags->enabledFor('demo', $id))->toBeTrue();
        }
    }

    expect(Rollout::bucket('demo', 'user', 7))->toBe(Rollout::bucket('demo', 'user', 7));
});

test('con porcentaje parcial y sin sujeto la flag está apagada, y 0 y 100 son exactos', function () {
    $this->flags->setFlag('demo', ['enabled' => true, 'rollout_percentage' => 50]);
    expect($this->flags->enabledFor('demo'))->toBeFalse();

    $this->flags->setFlag('demo', ['rollout_percentage' => 100]);
    expect($this->flags->enabledFor('demo'))->toBeTrue();

    $this->flags->setFlag('demo', ['rollout_percentage' => 0]);
    expect($this->flags->enabledFor('demo', 5, 5))->toBeFalse();
});

test('el porcentaje por tenant usa el hash del tenant', function () {
    $this->flags->setFlag('demo', ['enabled' => true, 'rollout_percentage' => 50, 'rollout_by' => 'tenant']);

    foreach ([$this->a->id, $this->b->id] as $id) {
        expect($this->flags->enabledFor('demo', 1, $id))->toBe(Rollout::includes('demo', 'tenant', $id, 50));
    }
});

test('la caché se invalida al escribir flags y overrides', function () {
    expect($this->flags->enabledFor('demo', null, $this->a->id))->toBeFalse(); // llena caché
    $this->flags->setFlag('demo', ['enabled' => true]);
    expect($this->flags->enabledFor('demo', null, $this->a->id))->toBeTrue();
    $this->flags->setTenantOverride('demo', $this->a->id, false);
    expect($this->flags->enabledFor('demo', null, $this->a->id))->toBeFalse();
    $this->flags->clearTenantOverride('demo', $this->a->id);
    expect($this->flags->enabledFor('demo', null, $this->a->id))->toBeTrue();
});

test('el middleware feature respeta BD y override del tenant', function () {
    Route::middleware(['api', 'auth:api', 'tenant', 'feature:demo'])->get('/_ff/demo', fn () => response()->json(['ok' => true]));

    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());
    $this->addMember($this->a, $user);
    $this->addMember($this->b, $user);
    $h = fn ($t) => ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $t->id];

    $this->asHeaders($h($this->a))->getJson('/_ff/demo')->assertForbidden()->assertJsonPath('code', 'feature_disabled');

    $this->flags->setTenantOverride('demo', $this->a->id, true);
    $this->asHeaders($h($this->a))->getJson('/_ff/demo')->assertOk();
    $this->asHeaders($h($this->b))->getJson('/_ff/demo')->assertForbidden();

    expect(class_exists(CheckFeatureFlag::class))->toBeTrue();
});

test('la API de plataforma gestiona flags y exige permiso', function () {
    $this->asHeaders(ffAdmin())->putJson('/admin/feature-flags/demo', ['enabled' => true, 'rollout_percentage' => 40])
        ->assertOk()->assertJsonPath('data.rollout_percentage', 40);
    $this->asHeaders(ffAdmin())->putJson('/admin/feature-flags/demo', ['rollout_percentage' => 140])->assertStatus(422);
    $this->asHeaders(ffAdmin())->putJson('/admin/feature-flags/nada', ['enabled' => true])
        ->assertNotFound()->assertJsonPath('code', 'feature_unknown');
    $this->asHeaders(ffAdmin())->putJson("/admin/feature-flags/demo/tenants/{$this->a->id}", ['enabled' => true])->assertOk();
    $this->asHeaders(ffAdmin())->getJson('/admin/feature-flags')->assertOk()->assertJsonFragment(['key' => 'demo']);
    $this->asHeaders(ffAdmin())->deleteJson('/admin/feature-flags/demo')->assertOk();

    $plain = User::factory()->create();
    $plain->assignRole(Role::where('name', 'user')->first());
    $h = ['Authorization' => 'Bearer '.JWTAuth::fromUser($plain)];
    $this->asHeaders($h)->getJson('/admin/feature-flags')->assertForbidden();
    $this->asHeaders($h)->putJson('/admin/feature-flags/demo', ['enabled' => true])->assertForbidden();
    $this->asHeaders([])->putJson('/admin/feature-flags/demo', [])->assertUnauthorized();
});

test('el tenant ve solo las flags evaluadas para sí', function () {
    $this->flags->setTenantOverride('demo', $this->a->id, true);

    $user = User::factory()->create();
    $this->addMember($this->a, $user);
    $this->addMember($this->b, $user);
    $user->givePermissionTo('tenant-settings.read');
    $h = fn ($t) => ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $t->id];

    $this->asHeaders($h($this->a))->getJson('/tenant/features')->assertOk()->assertJsonPath('data.demo.enabled', true);
    $this->asHeaders($h($this->b))->getJson('/tenant/features')->assertOk()->assertJsonPath('data.demo.enabled', false);
});

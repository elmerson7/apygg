<?php

use App\Enums\TenantMembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $ctx = app(TenantContext::class);
    $ctx->run($this->a, fn () => TenantNote::create(['title' => 'nota A']));
    $ctx->run($this->b, fn () => TenantNote::create(['title' => 'nota B']));

    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_tenancy/notes', fn () => response()->json([
        'titles' => TenantNote::query()->pluck('title')->all(),
        'tenant' => app(TenantContext::class)->id(),
    ]));
    Route::middleware(['api', 'auth:api', 'tenant:optional'])->get('/_tenancy/optional', fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
    ]));
    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_tenancy/{tenant}/route', fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
    ]));
    Route::middleware(['api', 'auth:api'])->get('/_tenancy/no-middleware', fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
    ]));
});

function asTenantUser($test, $tenant = null)
{
    $user = User::factory()->create();
    if ($tenant) {
        $test->addMember($tenant, $user);
    }
    $token = JWTAuth::fromUser($user);

    return [$user, fn (?string $h = null, ?string $uri = null) => $test->withToken($token)
        ->withHeaders($h ? ['X-Tenant' => $h] : [])->getJson($uri ?? '/_tenancy/notes')];
}

test('un miembro activo entra y solo ve los datos de su tenant', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call('acme')->assertOk()->assertJson(['titles' => ['nota A'], 'tenant' => $this->a->id]);
});

test('el tenant se puede indicar por id en la cabecera', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call((string) $this->a->id)->assertOk()->assertJson(['titles' => ['nota A']]);
});

test('un usuario que no es miembro recibe 403', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call('beta')->assertForbidden()->assertJson(['type' => 'tenant_forbidden', 'code' => 'tenant_invalid']);
});

test('un tenant inexistente responde igual que uno ajeno (sin revelar existencia)', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call('no-existe')->assertForbidden()->assertJson(['type' => 'tenant_forbidden', 'code' => 'tenant_invalid']);
});

test('una membresía suspendida o invitada no da acceso', function (TenantMembershipStatus $status) {
    $user = $this->createUser();
    $this->addMember($this->a, $user, $status);

    $this->withToken($this->loginAs($user))->withHeader('X-Tenant', 'acme')->getJson('/_tenancy/notes')->assertForbidden();
})->with([TenantMembershipStatus::Suspended, TenantMembershipStatus::Invited]);

test('un tenant suspendido responde 403 tenant_suspended aunque seas miembro', function () {
    $s = $this->makeTenant('susp', TenantStatus::Suspended);
    [, $call] = asTenantUser($this, $s);

    $call('susp')->assertForbidden()->assertJson(['type' => 'tenant_suspended', 'code' => 'tenant_inactive']);
});

test('sin cabecera la ruta que exige tenant responde 400', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call()->assertStatus(400)->assertJson(['type' => 'tenant_required', 'code' => 'tenant_required']);
});

test('sin autenticar responde 401 antes de resolver el tenant', function () {
    $this->withHeader('X-Tenant', 'acme')->getJson('/_tenancy/notes')->assertUnauthorized();
});

test('tenant:optional deja pasar sin tenant', function () {
    [, $call] = asTenantUser($this);

    $call(null, '/_tenancy/optional');
    $this->withToken($this->loginAs($this->createUser()))->getJson('/_tenancy/optional')->assertOk()->assertJson(['tenant' => null]);
});

test('un administrador de plataforma con tenants.access-any entra sin ser miembro', function () {
    $admin = $this->createAdmin();

    $this->withToken($this->loginAs($admin))->withHeader('X-Tenant', 'beta')->getJson('/_tenancy/notes')
        ->assertOk()->assertJson(['titles' => ['nota B']]);
});

test('un usuario con un rol sin tenants.access-any no entra a un tenant ajeno', function () {
    $manager = $this->createUser();
    $manager->assignRole(Role::where('name', 'manager')->first());

    $this->withToken($this->loginAs($manager))->withHeader('X-Tenant', 'beta')->getJson('/_tenancy/notes')->assertForbidden();
});

test('un administrador de plataforma tampoco entra a un tenant suspendido', function () {
    $this->makeTenant('susp', TenantStatus::Suspended);

    $this->withToken($this->loginAs($this->createAdmin()))->withHeader('X-Tenant', 'susp')->getJson('/_tenancy/notes')
        ->assertForbidden()->assertJson(['type' => 'tenant_suspended', 'code' => 'tenant_inactive']);
});

test('un usuario en dos tenants ve los datos del que indica la cabecera', function () {
    $user = $this->createUser();
    $this->addMember($this->a, $user);
    $this->addMember($this->b, $user);
    $token = $this->loginAs($user);

    $this->withToken($token)->withHeader('X-Tenant', 'acme')->getJson('/_tenancy/notes')->assertJson(['titles' => ['nota A']]);
    $this->withToken($token)->withHeader('X-Tenant', 'beta')->getJson('/_tenancy/notes')->assertJson(['titles' => ['nota B']]);
});

test('el tenant también se resuelve por parámetro de ruta', function () {
    config(['multi-tenancy.resolution.strategies' => ['route']]);
    [$user] = asTenantUser($this, $this->a);

    $this->withToken($this->loginAs($user))->getJson('/_tenancy/acme/route')->assertOk()->assertJson(['tenant' => $this->a->id]);
    $this->withToken($this->loginAs($user))->getJson('/_tenancy/beta/route')->assertForbidden();
});

test('el tenant también se resuelve por subdominio', function () {
    config(['multi-tenancy.resolution.strategies' => ['subdomain'], 'multi-tenancy.resolution.base_domain' => 'app.test']);
    [$user] = asTenantUser($this, $this->a);

    $this->withToken($this->loginAs($user))->getJson('http://acme.app.test/_tenancy/notes')->assertOk()->assertJson(['titles' => ['nota A']]);
    $this->withToken($this->loginAs($user))->getJson('http://beta.app.test/_tenancy/notes')->assertForbidden();
});

test('el tenant no se filtra a la siguiente petición', function () {
    [, $call] = asTenantUser($this, $this->a);

    $call('acme')->assertOk();

    expect(app(TenantContext::class)->current())->toBeNull();

    $this->withToken($this->loginAs($this->createUser()))->getJson('/_tenancy/no-middleware')->assertJson(['tenant' => null]);
});

test('con multi-tenancy desactivado el middleware no hace nada', function () {
    config(['multi-tenancy.enabled' => false]);
    [, $call] = asTenantUser($this);

    $call(null, '/_tenancy/optional')->assertOk()->assertJson(['tenant' => null]);
    $call()->assertOk()->assertJson(['tenant' => null]);
});

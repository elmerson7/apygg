<?php

use App\Listeners\ResetTenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Tenancy\PermissionTeamsSchema;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/*
 * El esquema de equipos se aplica DENTRO de la transacción del test (el DDL de PostgreSQL es
 * transaccional): se revierte solo y no afecta al resto de la suite.
 */
function olvidarColumnasCacheadasDeEloquent(): void
{
    (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();

    PermissionTeamsSchema::up();
    // Eloquent cachea en estático (por proceso) las columnas de cada tabla para $guarded:
    // si otra prueba creó un Role antes del DDL, tenant_id se descartaría en silencio.
    olvidarColumnasCacheadasDeEloquent();
    config(['permission.teams' => true]);
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->initializeCache();
    $this->registrar->setPermissionsTeamId(PermissionTeamsSchema::PLATFORM_TEAM);

    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);
});

afterEach(function () {
    olvidarColumnasCacheadasDeEloquent();
    config(['permission.teams' => false]);
    app(PermissionRegistrar::class)->initializeCache();
});

test('las asignaciones de rol son por tenant: admin en A no es admin en B ni en la plataforma', function () {
    $user = User::factory()->create();
    $this->ctx->run($this->a, fn () => $user->assignRole('admin'));

    $enA = $this->ctx->run($this->a, fn () => User::find($user->id)->hasRole('admin'));
    $enB = $this->ctx->run($this->b, fn () => User::find($user->id)->hasRole('admin'));
    $enPlataforma = User::find($user->id)->hasRole('admin');

    expect($enA)->toBeTrue()->and($enB)->toBeFalse()->and($enPlataforma)->toBeFalse();
});

test('un admin de A es super admin en A pero no en B', function () {
    $user = User::factory()->create();
    $this->ctx->run($this->a, fn () => $user->assignRole('admin'));

    expect($this->ctx->run($this->a, fn () => User::find($user->id)->isAdmin()))->toBeTrue()
        ->and($this->ctx->run($this->b, fn () => User::find($user->id)->isAdmin()))->toBeFalse();
});

test('un admin de la plataforma (equipo 0) es admin en cualquier tenant', function () {
    $user = User::factory()->create();
    $user->assignRole('admin'); // sin tenant activo: equipo de la plataforma

    $enA = $this->ctx->run($this->a, fn () => User::find($user->id)->isAdmin());
    $enB = $this->ctx->run($this->b, fn () => User::find($user->id)->isAdmin());

    expect($enA)->toBeTrue()->and($enB)->toBeTrue()
        ->and(User::find($user->id)->isPlatformAdmin())->toBeTrue();
});

test('un admin de A no es admin de la plataforma', function () {
    $user = User::factory()->create();
    $this->ctx->run($this->a, fn () => $user->assignRole('admin'));

    expect(User::find($user->id)->isPlatformAdmin())->toBeFalse();
});

test('el equipo de permisos sigue al tenant activo y vuelve a la plataforma al terminar', function () {
    $this->ctx->run($this->a, function () {
        expect($this->registrar->getPermissionsTeamId())->toBe($this->a->id);
        $this->ctx->run($this->b, fn () => expect($this->registrar->getPermissionsTeamId())->toBe($this->b->id));
        expect($this->registrar->getPermissionsTeamId())->toBe($this->a->id);
    });

    expect($this->registrar->getPermissionsTeamId())->toBe(PermissionTeamsSchema::PLATFORM_TEAM);
});

test('el listener de Octane devuelve el equipo de permisos a la plataforma', function () {
    $this->registrar->setPermissionsTeamId($this->a->id);

    (new ResetTenantContext)->handle(new stdClass);

    expect($this->registrar->getPermissionsTeamId())->toBe(PermissionTeamsSchema::PLATFORM_TEAM);
});

test('un rol creado en la plataforma es global y uno creado en un tenant es de ese tenant', function () {
    $global = Role::create(['name' => 'auditor', 'display_name' => 'auditor', 'guard_name' => 'api']);
    $propio = $this->ctx->run($this->a, fn () => Role::create(['name' => 'gerente', 'display_name' => 'gerente', 'guard_name' => 'api']));

    expect($global->tenant_id)->toBeNull()
        ->and($propio->tenant_id)->toBe($this->a->id);
});

test('un rol propio de A no es visible ni asignable desde B', function () {
    $propio = $this->ctx->run($this->a, fn () => Role::create(['name' => 'gerente', 'display_name' => 'gerente', 'guard_name' => 'api']));
    $user = User::factory()->create();

    $visiblesB = $this->ctx->run($this->b, fn () => Role::visibleInScope()->pluck('name')->all());
    $visiblesA = $this->ctx->run($this->a, fn () => Role::visibleInScope()->pluck('name')->all());

    expect($visiblesB)->not->toContain('gerente')->and($visiblesA)->toContain('gerente');

    $this->ctx->run($this->b, fn () => $user->assignRole($propio->name));
})->throws(RoleDoesNotExist::class);

test('dos roles globales no pueden llamarse igual', function () {
    Role::create(['name' => 'duplicado', 'display_name' => 'duplicado', 'guard_name' => 'api']);

    expect(fn () => Role::query()->create(['name' => 'duplicado', 'display_name' => 'duplicado', 'guard_name' => 'api']))->toThrow(QueryException::class);
});

test('un tenant no puede modificar un rol global ni uno de otro tenant', function () {
    $global = Role::where('name', 'user')->first();
    $deB = $this->ctx->run($this->b, fn () => Role::create(['name' => 'de-b', 'display_name' => 'de-b', 'guard_name' => 'api']));
    $admin = User::factory()->create();
    $this->ctx->run($this->a, fn () => $admin->assignRole('admin'));
    $policy = new RolePolicy;

    $resultados = $this->ctx->run($this->a, fn () => [
        $policy->update($admin, $global), $policy->delete($admin, $global), $policy->assignPermission($admin, $global),
        $policy->update($admin, $deB), $policy->assignPermission($admin, $deB),
    ]);

    expect($resultados)->each->toBeFalse()
        // La plataforma sí puede tocar el catálogo global
        ->and($policy->assignPermission(tap(User::factory()->create(), fn ($u) => $u->assignRole('admin')), $global))->toBeTrue();
});

function stPing($test, User $u)
{
    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_teams/ping', fn () => response()->json(['tenant' => app(TenantContext::class)->id()]));

    return $test->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($u), 'X-Tenant' => (string) $test->b->id])->getJson('/_teams/ping');
}

test('tenants.access-any concedido en la plataforma permite entrar a cualquier tenant', function () {
    $soporte = User::factory()->create();
    $soporte->givePermissionTo(Permission::where('name', 'tenants.access-any')->first());

    stPing($this, $soporte)->assertOk()->assertJson(['tenant' => $this->b->id]);
});

test('tenants.access-any concedido solo dentro de A no permite entrar a B', function () {
    $soporteA = User::factory()->create();
    $this->ctx->run($this->a, fn () => $soporteA->givePermissionTo(Permission::where('name', 'tenants.access-any')->first()));

    stPing($this, $soporteA)->assertForbidden();
});

test('las relaciones cacheadas del usuario no mezclan equipos al entrar por el middleware', function () {
    $user = User::factory()->create();
    $this->addMember($this->a, $user);
    $this->ctx->run($this->a, fn () => $user->assignRole('admin'));

    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_teams/admin', fn () => response()->json(['admin' => request()->user('api')->hasRole('admin')]));

    $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $this->a->id])
        ->getJson('/_teams/admin')->assertOk()->assertJson(['admin' => true]);
});

test('el esquema de equipos es reversible', function () {
    expect(PermissionTeamsSchema::isApplied())->toBeTrue();

    config(['permission.teams' => false]);
    PermissionTeamsSchema::down();

    expect(PermissionTeamsSchema::isApplied())->toBeFalse();
});

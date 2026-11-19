<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

function miembro(Tenant $tenant, string $role = 'member', array $attrs = [], string $status = 'active'): User
{
    $user = User::factory()->create($attrs);
    $tenant->users()->attach($user->getKey(), ['status' => $status, 'role' => $role, 'joined_at' => now()]);
    TenantRoles::sync($user, $tenant, $role);

    return $user;
}

function h(User $user, string $tenant): array
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken(); // el JWT singleton y el guard cachean el token/usuario de la petición anterior

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => $tenant];
}

function rolDe(Tenant $tenant, User $user): ?string
{
    return DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role');
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ownerA = miembro($this->a, 'owner');
    $this->adminA = miembro($this->a, 'admin');
    $this->rasoA = miembro($this->a, 'member', ['name' => 'Zeta Raso']);
    $this->ownerB = miembro($this->b, 'owner');
});

test('lista solo los miembros del tenant activo, paginada y con filtros', function () {
    $this->getJson('/tenant/members', h($this->ownerA, 'acme'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissing(['email' => $this->ownerB->email]);

    $this->getJson('/tenant/members?per_page=2', h($this->ownerA, 'acme'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/tenant/members?role=admin', h($this->ownerA, 'acme'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->adminA->id);
    $this->getJson('/tenant/members?search=zeta', h($this->ownerA, 'acme'))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/tenant/members?status=bogus', h($this->ownerA, 'acme'))->assertUnprocessable();
});

test('un miembro raso puede ver la lista pero no gestionar (403)', function () {
    $this->getJson('/tenant/members', h($this->rasoA, 'acme'))->assertOk();
    $this->patchJson('/tenant/members/'.$this->adminA->id, ['role' => 'member'], h($this->rasoA, 'acme'))->assertForbidden();
    $this->deleteJson('/tenant/members/'.$this->adminA->id, [], h($this->rasoA, 'acme'))->assertForbidden();
    expect(rolDe($this->a, $this->adminA))->toBe('admin');
});

test('sin token da 401 y sin membresía en el tenant da 403', function () {
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    $this->getJson('/tenant/members', ['X-Tenant' => 'acme'])->assertUnauthorized();
    $this->getJson('/tenant/members', h($this->ownerB, 'acme'))->assertForbidden();
});

test('el dueño cambia el rol de un miembro y se refleja en la membresía y en Spatie', function () {
    $this->patchJson('/tenant/members/'.$this->rasoA->id, ['role' => 'admin'], h($this->ownerA, 'acme'))
        ->assertOk()->assertJsonPath('data.role', 'admin');

    expect(rolDe($this->a, $this->rasoA))->toBe('admin')
        ->and($this->rasoA->fresh()->hasRole('tenant-admin'))->toBeTrue();
});

test('un admin no puede escalar a nadie por encima de su rango ni tocar a un dueño', function () {
    $this->patchJson('/tenant/members/'.$this->rasoA->id, ['role' => 'owner'], h($this->adminA, 'acme'))
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    $this->patchJson('/tenant/members/'.$this->adminA->id, ['role' => 'owner'], h($this->adminA, 'acme'))->assertForbidden();
    $this->patchJson('/tenant/members/'.$this->ownerA->id, ['role' => 'member'], h($this->adminA, 'acme'))->assertForbidden();
    $this->deleteJson('/tenant/members/'.$this->ownerA->id, [], h($this->adminA, 'acme'))->assertForbidden();
    $this->postJson('/tenant/members/'.$this->ownerA->id.'/suspend', [], h($this->adminA, 'acme'))->assertForbidden();

    expect(rolDe($this->a, $this->rasoA))->toBe('member')->and(rolDe($this->a, $this->ownerA))->toBe('owner');
});

test('no se puede quitar, degradar ni suspender al último dueño', function () {
    $this->patchJson('/tenant/members/'.$this->ownerA->id, ['role' => 'admin'], h($this->ownerA, 'acme'))
        ->assertUnprocessable()->assertJsonPath('code', 'last_owner');
    $this->deleteJson('/tenant/members/'.$this->ownerA->id, [], h($this->ownerA, 'acme'))
        ->assertUnprocessable()->assertJsonPath('code', 'last_owner');

    expect(rolDe($this->a, $this->ownerA))->toBe('owner');
});

test('con dos dueños uno puede dejar el cargo y el último ya no puede', function () {
    $otro = miembro($this->a, 'owner');

    $this->patchJson('/tenant/members/'.$this->ownerA->id, ['role' => 'member'], h($otro, 'acme'))->assertOk();
    $this->postJson('/tenant/members/'.$otro->id.'/suspend', [], h($otro, 'acme'))->assertUnprocessable();
    $this->deleteJson('/tenant/members/'.$otro->id, [], h($otro, 'acme'))->assertUnprocessable()->assertJsonPath('code', 'last_owner');
});

test('un dueño suspendido no cuenta como dueño activo para la salvaguarda', function () {
    $otro = miembro($this->a, 'owner');
    $this->postJson('/tenant/members/'.$otro->id.'/suspend', [], h($this->ownerA, 'acme'))->assertOk();

    $this->deleteJson('/tenant/members/'.$this->ownerA->id, [], h($this->ownerA, 'acme'))->assertUnprocessable()->assertJsonPath('code', 'last_owner');
});

test('suspender impide entrar al tenant y reactivar lo devuelve', function () {
    $this->postJson('/tenant/members/'.$this->rasoA->id.'/suspend', [], h($this->ownerA, 'acme'))
        ->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->getJson('/tenant/members', h($this->rasoA, 'acme'))->assertForbidden();

    $this->postJson('/tenant/members/'.$this->rasoA->id.'/reactivate', [], h($this->ownerA, 'acme'))
        ->assertOk()->assertJsonPath('data.status', 'active');
    $this->getJson('/tenant/members', h($this->rasoA, 'acme'))->assertOk();
});

test('nadie puede suspenderse a sí mismo', function () {
    $this->postJson('/tenant/members/'.$this->adminA->id.'/suspend', [], h($this->adminA, 'acme'))
        ->assertUnprocessable()->assertJsonPath('code', 'cannot_suspend_self');
});

test('quitar a un miembro borra su membresía y su rol de tenant, no al usuario', function () {
    $this->deleteJson('/tenant/members/'.$this->rasoA->id, [], h($this->ownerA, 'acme'))->assertOk();

    expect(rolDe($this->a, $this->rasoA))->toBeNull()
        ->and(User::find($this->rasoA->id))->not->toBeNull();
    $this->getJson('/tenant/members', h($this->rasoA, 'acme'))->assertForbidden();
});

test('un miembro puede salir él mismo de la empresa si no es el último dueño', function () {
    $this->deleteJson('/tenant/members/'.$this->rasoA->id, [], h($this->rasoA, 'acme'))->assertForbidden(); // sin permiso manage
    $this->deleteJson('/tenant/members/'.$this->adminA->id, [], h($this->adminA, 'acme'))->assertOk();
    expect(rolDe($this->a, $this->adminA))->toBeNull();
});

test('aislamiento A/B: los dueños no ven ni tocan miembros del otro tenant', function () {
    $idB = $this->ownerB->id;
    $miembroB = miembro($this->b, 'member');

    $this->getJson('/tenant/members', h($this->ownerA, 'acme'))->assertOk()->assertJsonMissing(['user_id' => $idB]);
    $this->patchJson("/tenant/members/{$miembroB->id}", ['role' => 'admin'], h($this->ownerA, 'acme'))->assertNotFound();
    $this->postJson("/tenant/members/{$miembroB->id}/suspend", [], h($this->ownerA, 'acme'))->assertNotFound();
    $this->deleteJson("/tenant/members/{$miembroB->id}", [], h($this->ownerA, 'acme'))->assertNotFound();

    expect(rolDe($this->b, $miembroB))->toBe('member');
    // y con la cabecera de B siendo dueño de A: acceso denegado
    $this->patchJson("/tenant/members/{$miembroB->id}", ['role' => 'admin'], h($this->ownerA, 'beta'))->assertForbidden();
});

test('un usuario con rol de tenant en A sin permisos en B no puede gestionar B aunque sea miembro raso', function () {
    // ownerA es además miembro raso de B: tiene el permiso global (equipos apagados) pero la jerarquía por membresía manda
    $this->b->users()->attach($this->ownerA->id, ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);
    $adminB = miembro($this->b, 'admin');

    $this->patchJson('/tenant/members/'.$adminB->id, ['role' => 'member'], h($this->ownerA, 'beta'))
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    $this->deleteJson('/tenant/members/'.$this->ownerB->id, [], h($this->ownerA, 'beta'))->assertForbidden();
    expect(rolDe($this->b, $adminB))->toBe('admin');
});

test('el rol de dueño en A no da poderes de gestión en B a quien allí es miembro raso, ni sobre otros rasos', function () {
    // Con equipos apagados el rol de Spatie `tenant-owner` es global: la ruta pasa, el servicio debe frenarlo.
    $this->b->users()->attach($this->ownerA->id, ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);
    $rasoB = miembro($this->b, 'member');

    $this->postJson("/tenant/members/{$rasoB->id}/suspend", [], h($this->ownerA, 'beta'))
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    $this->deleteJson("/tenant/members/{$rasoB->id}", [], h($this->ownerA, 'beta'))->assertForbidden();
    $this->postJson('/tenant/invitations', ['email' => 'nuevo@example.com', 'role' => 'member'], h($this->ownerA, 'beta'))
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    $this->getJson('/tenant/invitations', h($this->ownerA, 'beta'))->assertForbidden();

    expect(DB::table('tenant_user')->where('tenant_id', $this->b->id)->where('user_id', $rasoB->id)->value('status'))->toBe('active')
        ->and(DB::table('tenant_invitations')->where('tenant_id', $this->b->id)->count())->toBe(0);

    // En su propia empresa sigue pudiendo.
    $this->getJson('/tenant/invitations', h($this->ownerA, 'acme'))->assertOk();
});

test('un rol inexistente da 422 y un usuario que no es miembro da 404', function () {
    $this->patchJson('/tenant/members/'.$this->rasoA->id, ['role' => 'dios'], h($this->ownerA, 'acme'))->assertUnprocessable();
    $this->patchJson('/tenant/members/999999', ['role' => 'member'], h($this->ownerA, 'acme'))->assertNotFound();
});

test('con multiempresa apagado las rutas de miembros responden 404', function () {
    config(['multi-tenancy.enabled' => false]);

    $this->getJson('/tenant/members', h($this->ownerA, 'acme'))->assertNotFound();
});

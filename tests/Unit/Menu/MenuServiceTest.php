<?php

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(MenuService::class);
    $this->user = User::factory()->create();
});

function menuItem(array $attributes = []): MenuItem
{
    return MenuItem::create(array_merge([
        'key' => 'item-'.uniqid(),
        'label' => 'Ítem',
        'sort_order' => 0,
        'is_active' => true,
    ], $attributes));
}

function giveUserPermission(User $user, string $name): void
{
    $permission = Permission::firstOrCreate(
        ['name' => $name],
        ['resource' => explode('.', $name)[0], 'action' => explode('.', $name)[1] ?? 'read']
    );

    $user->permissions()->syncWithoutDetaching([$permission->id]);
}

test('muestra el ítem cuando el usuario tiene su permiso', function () {
    menuItem(['key' => 'usuarios', 'permission' => 'users.read']);
    giveUserPermission($this->user, 'users.read');

    expect($this->service->getUserMenuKeys($this->user))->toBe(['usuarios']);
});

test('oculta el ítem cuando el usuario no tiene el permiso', function () {
    menuItem(['key' => 'usuarios', 'permission' => 'users.read']);

    expect($this->service->getUserMenuKeys($this->user))->toBe([]);
});

test('un ítem sin permiso y sin roles no lo ve nadie', function () {
    menuItem(['key' => 'suelto']);

    expect($this->service->getUserMenuKeys($this->user))->toBe([]);
});

test('un ítem sin permiso se ve si el rol lo tiene asignado', function () {
    $role = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
    $this->user->roles()->attach($role->id);

    $item = menuItem(['key' => 'suelto']);
    $item->roles()->attach($role->id);

    expect($this->service->getUserMenuKeys($this->user))->toBe(['suelto']);
});

test('los ítems inactivos no se muestran', function () {
    menuItem(['key' => 'usuarios', 'permission' => 'users.read', 'is_active' => false]);
    giveUserPermission($this->user, 'users.read');

    expect($this->service->getUserMenuKeys($this->user))->toBe([]);
});

test('un permiso inexistente oculta el ítem en lugar de reventar', function () {
    menuItem(['key' => 'fantasma', 'permission' => 'modulo.que.no.existe']);

    expect($this->service->getUserMenuKeys($this->user))->toBe([]);
});

test('respeta el orden de sort_order', function () {
    menuItem(['key' => 'b', 'permission' => 'users.read', 'sort_order' => 20]);
    menuItem(['key' => 'a', 'permission' => 'users.read', 'sort_order' => 10]);
    giveUserPermission($this->user, 'users.read');

    expect($this->service->getUserMenuKeys($this->user))->toBe(['a', 'b']);
});

test('el árbol anida los hijos bajo su padre', function () {
    menuItem(['key' => 'config', 'permission' => 'users.read', 'sort_order' => 1]);
    menuItem(['key' => 'config.correo', 'permission' => 'users.read', 'parent_key' => 'config', 'sort_order' => 2]);
    giveUserPermission($this->user, 'users.read');

    $tree = $this->service->getUserMenuTree($this->user);

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['key'])->toBe('config')
        ->and($tree[0]['children'])->toHaveCount(1)
        ->and($tree[0]['children'][0]['key'])->toBe('config.correo');
});

test('un hijo cuyo padre no es visible se descarta del árbol', function () {
    menuItem(['key' => 'config', 'permission' => 'system.settings']);
    menuItem(['key' => 'config.correo', 'permission' => 'users.read', 'parent_key' => 'config']);
    giveUserPermission($this->user, 'users.read');

    expect($this->service->getUserMenuTree($this->user))->toBe([])
        ->and($this->service->getUserMenuKeys($this->user))->toBe(['config.correo']);
});

test('syncRoleAssignments reemplaza las asignaciones', function () {
    $role = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
    $item = menuItem(['key' => 'suelto']);

    $this->service->syncRoleAssignments([['key' => 'suelto', 'role_ids' => [$role->id]]]);
    expect($item->fresh()->roles)->toHaveCount(1);

    $this->service->syncRoleAssignments([['key' => 'suelto', 'role_ids' => []]]);
    expect($item->fresh()->roles)->toHaveCount(0);
});

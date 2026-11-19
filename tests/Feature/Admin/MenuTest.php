<?php

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de menú. /user/menu lo pide cualquier usuario autenticado; el
 * catálogo (/admin/menu/items*) exige menu.manage. El menú es presentación:
 * estas pruebas fijan también que ocultar un ítem no sustituye al permiso.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function menuHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function menuUsuarioSinPermisos(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'user')->value('id'));

    return $user;
}

function menuUsuarioConPermiso(string $permiso): User
{
    $user = menuUsuarioSinPermisos();
    $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));

    return $user;
}

function menuItemCrear(array $attributes = []): MenuItem
{
    return MenuItem::create(array_merge([
        'key' => 'item.'.uniqid(),
        'label' => 'Ítem',
        'route' => '/ruta',
        'icon' => 'list',
        'permission' => null,
        'parent_key' => null,
        'sort_order' => 10,
        'is_active' => true,
    ], $attributes));
}

// ── /user/menu ──────────────────────────────────────────────────────────────

test('un usuario autenticado recibe su menú en árbol con sus claves', function () {
    menuItemCrear(['key' => 'publico', 'label' => 'Público', 'permission' => null]);
    menuItemCrear(['key' => 'usuarios', 'label' => 'Usuarios', 'permission' => 'users.read']);

    $user = menuUsuarioConPermiso('users.read');

    $this->withHeaders(menuHeaders($user))->getJson('/user/menu')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'message', 'data' => ['items', 'keys']])
        ->assertJsonPath('data.keys', ['usuarios']);
});

test('el menú no muestra un ítem con permiso que el usuario no tiene', function () {
    menuItemCrear(['key' => 'usuarios', 'permission' => 'users.read']);

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->getJson('/user/menu')
        ->assertOk()
        ->assertJsonPath('data.keys', []);
});

test('un ítem visible solo por rol aparece a quien tiene ese rol', function () {
    $item = menuItemCrear(['key' => 'por-rol', 'permission' => null]);
    $user = menuUsuarioSinPermisos();
    $item->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(menuHeaders($user))->getJson('/user/menu')
        ->assertOk()
        ->assertJsonPath('data.keys', ['por-rol']);
});

test('un ítem inactivo no aparece en el menú', function () {
    menuItemCrear(['key' => 'apagado', 'permission' => null, 'is_active' => false]);
    $item = menuItemCrear(['key' => 'activo', 'permission' => null]);
    $item->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->getJson('/user/menu')
        ->assertOk()
        ->assertJsonPath('data.keys', ['activo']);
});

test('pedir el menú sin token responde 401', function () {
    $this->getJson('/user/menu')->assertUnauthorized();
});

// ── catálogo: /admin/menu/items ─────────────────────────────────────────────

test('quien tiene menu.manage lista el catálogo con roles disponibles', function () {
    menuItemCrear(['key' => 'usuarios', 'permission' => 'users.read']);

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->getJson('/admin/menu/items')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['items', 'roles']])
        ->assertJsonPath('data.items.0.key', 'usuarios');
});

test('un usuario sin menu.manage no lista el catálogo', function () {
    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->getJson('/admin/menu/items')
        ->assertForbidden()
        ->assertJsonPath('status', 403);
});

test('listar el catálogo sin token responde 401', function () {
    $this->getJson('/admin/menu/items')->assertUnauthorized();
});

// ── store ───────────────────────────────────────────────────────────────────

test('quien tiene menu.manage crea un ítem y queda en la base de datos', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->postJson('/admin/menu/items', [
        'key' => 'reportes',
        'label' => 'Reportes',
        'route' => '/admin/reportes',
        'permission' => 'users.read',
        'sort_order' => 5,
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'reportes');

    $this->assertDatabaseHas('menu_items', ['key' => 'reportes', 'permission' => 'users.read']);
});

test('crear un ítem sin menu.manage responde 403 y no lo guarda', function () {
    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->postJson('/admin/menu/items', [
        'key' => 'reportes',
        'label' => 'Reportes',
    ])->assertForbidden();

    $this->assertDatabaseMissing('menu_items', ['key' => 'reportes']);
});

test('crear un ítem sin token responde 401', function () {
    $this->postJson('/admin/menu/items', ['key' => 'reportes', 'label' => 'Reportes'])
        ->assertUnauthorized();
});

test('crear un ítem con una clave que ya existe responde 422', function () {
    menuItemCrear(['key' => 'repetido']);

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->postJson('/admin/menu/items', [
        'key' => 'repetido',
        'label' => 'Otro',
    ])->assertUnprocessable();

    expect(MenuItem::where('key', 'repetido')->count())->toBe(1);
});

test('crear un ítem con un permiso que no existe responde 422', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->postJson('/admin/menu/items', [
        'key' => 'fantasma',
        'label' => 'Fantasma',
        'permission' => 'modulo.que.no.existe',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('menu_items', ['key' => 'fantasma']);
});

test('crear un ítem con una clave con mayúsculas responde 422', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->postJson('/admin/menu/items', [
        'key' => 'Mayusculas',
        'label' => 'Mayúsculas',
    ])->assertUnprocessable();
});

test('crear un ítem que cuelga de un padre inexistente responde 422', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->postJson('/admin/menu/items', [
        'key' => 'hijo',
        'label' => 'Hijo',
        'parent_key' => 'no-existe',
    ])->assertUnprocessable();
});

// ── update ──────────────────────────────────────────────────────────────────

test('quien tiene menu.manage actualiza un ítem y asigna roles', function () {
    $item = menuItemCrear(['key' => 'usuarios', 'label' => 'Usuarios']);
    $rol = Role::where('name', 'user')->value('id');

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->putJson("/admin/menu/items/{$item->id}", [
        'label' => 'Gente',
        'role_ids' => [$rol],
    ])
        ->assertOk()
        ->assertJsonPath('data.label', 'Gente');

    $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'label' => 'Gente']);
    expect($item->fresh()->roles->pluck('id')->all())->toBe([$rol]);
});

test('actualizar un ítem sin menu.manage responde 403 y no lo cambia', function () {
    $item = menuItemCrear(['label' => 'Original']);

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->putJson("/admin/menu/items/{$item->id}", [
        'label' => 'Cambiado',
    ])->assertForbidden();

    $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'label' => 'Original']);
});

test('actualizar un ítem inexistente responde 404', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->putJson('/admin/menu/items/999999', [
        'label' => 'X',
    ])->assertNotFound();
});

test('actualizar un ítem con datos inválidos responde 422', function () {
    $item = menuItemCrear();

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->putJson("/admin/menu/items/{$item->id}", [
        'sort_order' => -1,
    ])->assertUnprocessable();
});

// ── visibility ──────────────────────────────────────────────────────────────

test('asignar visibilidad por rol guarda el rol del ítem', function () {
    $item = menuItemCrear(['key' => 'reportes', 'permission' => null]);
    $rol = Role::where('name', 'user')->value('id');

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))
        ->putJson('/admin/menu/items/visibility', [
            'items' => [['key' => 'reportes', 'role_ids' => [$rol]]],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($item->fresh()->roles->pluck('id')->all())->toBe([$rol]);
});

test('un ítem visible solo por rol no aparece a quien no tiene ese rol', function () {
    $item = menuItemCrear(['key' => 'reportes', 'permission' => null]);
    $item->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(menuHeaders(User::factory()->create()))->getJson('/user/menu')
        ->assertOk()
        ->assertJsonPath('data.keys', []);
});

test('la visibilidad por rol no concede el permiso del ítem', function () {
    // Ocultar o mostrar un ítem no protege nada: con permiso asignado manda el permiso.
    $item = menuItemCrear(['key' => 'usuarios', 'permission' => 'users.read']);
    $item->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->getJson('/user/menu')
        ->assertJsonPath('data.keys', []);
});

test('la visibilidad con una clave de ítem inexistente responde 422', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))
        ->putJson('/admin/menu/items/visibility', [
            'items' => [['key' => 'no-existe', 'role_ids' => []]],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);
});

test('la visibilidad sin lista de ítems responde 422', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))
        ->putJson('/admin/menu/items/visibility', ['items' => []])
        ->assertUnprocessable();
});

test('cambiar la visibilidad sin menu.manage responde 403', function () {
    menuItemCrear(['key' => 'usuarios']);

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))
        ->putJson('/admin/menu/items/visibility', [
            'items' => [['key' => 'usuarios', 'role_ids' => []]],
        ])
        ->assertForbidden();
});

// ── destroy ─────────────────────────────────────────────────────────────────

test('quien tiene menu.manage elimina un ítem sin hijos', function () {
    $item = menuItemCrear(['key' => 'sobrante']);

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->deleteJson("/admin/menu/items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('menu_items', ['id' => $item->id]);
});

test('no se elimina un ítem que tiene hijos y responde 422', function () {
    $padre = menuItemCrear(['key' => 'configuracion']);
    menuItemCrear(['key' => 'configuracion.menu', 'parent_key' => 'configuracion']);

    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->deleteJson("/admin/menu/items/{$padre->id}")
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('menu_items', ['id' => $padre->id, 'deleted_at' => null]);
});

test('eliminar un ítem sin menu.manage responde 403', function () {
    $item = menuItemCrear();

    $this->withHeaders(menuHeaders(menuUsuarioSinPermisos()))->deleteJson("/admin/menu/items/{$item->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'deleted_at' => null]);
});

test('eliminar un ítem inexistente responde 404', function () {
    $this->withHeaders(menuHeaders(menuUsuarioConPermiso('menu.manage')))->deleteJson('/admin/menu/items/999999')
        ->assertNotFound();
});

test('eliminar un ítem sin token responde 401', function () {
    $item = menuItemCrear();

    $this->deleteJson("/admin/menu/items/{$item->id}")->assertUnauthorized();
});

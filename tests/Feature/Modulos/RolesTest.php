<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /roles y /permissions (routes/api/roles.php). Los casos de
 * escalada de privilegios ya están en Authorization/UserRolesEscalationTest;
 * aquí, el CRUD con su camino feliz, 401/403/404/422 y el efecto en la BD.
 */

uses(RefreshDatabase::class);

function rolesCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function rolesUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();

    foreach ($permisos as $permiso) {
        $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));
    }

    return $user;
}

function rolesCrear(array $extra = []): Role
{
    return Role::create(array_merge([
        'name' => 'rol-prueba-'.bin2hex(random_bytes(3)),
        'display_name' => 'Rol de prueba',
        'description' => 'Creado por una prueba',
    ], $extra));
}

// ── roles: listado y detalle ────────────────────────────────────────────────

test('quien tiene roles.read lista los roles paginados', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))->getJson('/roles')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta']);
});

test('el listado de roles filtra por texto', function () {
    $buscado = rolesCrear(['name' => 'coordinador-x', 'display_name' => 'Coordinador Extraordinario']);
    rolesCrear(['name' => 'otro-rol-x', 'display_name' => 'Otro']);

    $ids = collect($this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))
        ->getJson('/roles?search=Extraordinario')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($buscado->id)->toHaveCount(1);
});

test('el listado de roles sin token responde 401 y sin roles.read responde 403', function () {
    $this->getJson('/roles')->assertUnauthorized();
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon()))->getJson('/roles')->assertForbidden();
});

test('quien tiene roles.read ve un rol con sus datos', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))->getJson("/roles/{$rol->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $rol->id)
        ->assertJsonPath('data.name', $rol->name);
});

test('pedir un rol inexistente responde 404', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))->getJson('/roles/999999')
        ->assertNotFound();
});

// ── roles: alta, edición y baja ─────────────────────────────────────────────

test('quien tiene roles.create crea un rol y queda guardado', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.create')))->postJson('/roles', [
        'name' => 'editor-x',
        'display_name' => 'Editor',
    ])->assertStatus(201);

    $this->assertDatabaseHas('roles', ['name' => 'editor-x', 'display_name' => 'Editor']);
});

test('crear un rol con un nombre ya usado responde 422', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.create')))->postJson('/roles', [
        'name' => 'admin',
        'display_name' => 'Duplicado',
    ])->assertUnprocessable();
});

test('crear un rol sin nombre visible responde 422', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.create')))->postJson('/roles', [
        'name' => 'sin-nombre-visible',
    ])->assertUnprocessable();
});

test('crear un rol sin roles.create responde 403 y no lo guarda', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))->postJson('/roles', [
        'name' => 'no-creado',
        'display_name' => 'No',
    ])->assertForbidden();

    $this->assertDatabaseMissing('roles', ['name' => 'no-creado']);
});

test('quien tiene roles.update cambia el nombre visible de un rol', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.update')))->putJson("/roles/{$rol->id}", [
        'display_name' => 'Nombre nuevo',
    ])->assertOk();

    $this->assertDatabaseHas('roles', ['id' => $rol->id, 'display_name' => 'Nombre nuevo']);
});

test('actualizar un rol inexistente responde 404', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))->putJson('/roles/999999', [
        'display_name' => 'X',
    ])->assertNotFound();
});

test('un manager no puede cambiar el rol admin aunque tenga roles.update', function () {
    $admin = Role::where('name', 'admin')->value('id');

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.update')))->putJson("/roles/{$admin}", [
        'display_name' => 'Hackeado',
    ])->assertForbidden();

    $this->assertDatabaseHas('roles', ['id' => $admin, 'display_name' => 'Administrador']);
});

test('quien tiene roles.delete elimina un rol', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.delete')))->deleteJson("/roles/{$rol->id}")
        ->assertOk();

    expect(Role::find($rol->id))->toBeNull();
});

test('eliminar un rol sin roles.delete responde 403 y lo conserva', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))->deleteJson("/roles/{$rol->id}")
        ->assertForbidden();

    expect(Role::find($rol->id))->not->toBeNull();
});

// ── permisos de un rol ──────────────────────────────────────────────────────

test('quien tiene roles.read ve los permisos de un rol', function () {
    $rol = rolesCrear();
    $rol->givePermissionTo('users.read');

    $nombres = collect($this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))
        ->getJson("/roles/{$rol->id}/permissions")
        ->assertOk()
        ->json('data'))->pluck('name');

    expect($nombres)->toContain('users.read');
});

test('sustituir los permisos de un rol los deja guardados', function () {
    $rol = rolesCrear();
    $lectura = Permission::where('name', 'users.read')->value('id');
    $escritura = Permission::where('name', 'users.update')->value('id');

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->putJson("/roles/{$rol->id}/permissions", ['permissions' => [$lectura, $escritura]])
        ->assertOk();

    expect($rol->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['users.read', 'users.update']);
});

test('sustituir permisos sin la lista de permisos responde 422', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->putJson("/roles/{$rol->id}/permissions", [])
        ->assertUnprocessable();
});

test('sustituir permisos con un id que no existe responde 422', function () {
    $rol = rolesCrear();

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->putJson("/roles/{$rol->id}/permissions", ['permissions' => [999999]])
        ->assertUnprocessable();
});

test('los permisos de un rol inexistente responden 404', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->getJson('/roles/999999/permissions')
        ->assertNotFound();
});

// ── catálogo de permisos ────────────────────────────────────────────────────

test('quien tiene permissions.read lista el catálogo de permisos', function () {
    $ids = collect($this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read')))
        ->getJson('/permissions?per_page=500')
        ->assertOk()
        ->json('data'))->pluck('name');

    expect($ids)->toContain('users.read')->toContain('roles.read');
});

test('el catálogo de permisos filtra por recurso', function () {
    $nombres = collect($this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read')))
        ->getJson('/permissions?resource=users&per_page=500')
        ->assertOk()
        ->json('data'))->pluck('name');

    expect($nombres)->toContain('users.read')->not->toContain('roles.read');
});

test('el catálogo de permisos sin permissions.read responde 403 y sin token 401', function () {
    $this->getJson('/permissions')->assertUnauthorized();
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('roles.read')))->getJson('/permissions')->assertForbidden();
});

test('los permisos agrupados vienen indexados por recurso', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read')))->getJson('/permissions/grouped')
        ->assertOk()
        ->assertJsonPath('data.users.0.name', 'users.create')
        ->assertJsonFragment(['name' => 'users.read']);
});

test('ver un permiso por id devuelve su detalle', function () {
    $permiso = Permission::where('name', 'users.read')->firstOrFail();

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read')))->getJson("/permissions/{$permiso->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'users.read');
});

test('pedir un permiso inexistente responde 404', function () {
    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read')))->getJson('/permissions/999999')
        ->assertNotFound();
});

test('crear un permiso exige ser administrador aunque tenga permissions.create', function () {
    $manager = rolesUsuarioCon('permissions.create');

    $this->withHeaders(rolesCabeceras($manager))->postJson('/permissions', [
        'name' => 'reportes.exportar',
        'display_name' => 'Exportar reportes',
    ])->assertForbidden();

    $this->assertDatabaseMissing('permissions', ['name' => 'reportes.exportar']);
});

test('un administrador crea un permiso con formato recurso.accion', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))->postJson('/permissions', [
        'name' => 'reportes.exportar',
        'display_name' => 'Exportar reportes',
    ])->assertStatus(201);

    $this->assertDatabaseHas('permissions', ['name' => 'reportes.exportar', 'resource' => 'reportes', 'action' => 'exportar']);
});

test('crear un permiso con un nombre ya existente responde 422', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))->postJson('/permissions', [
        'name' => 'users.read',
        'display_name' => 'Repetido',
    ])->assertUnprocessable();
});

test('crear un permiso cuyo nombre no es recurso.accion responde 422', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))->postJson('/permissions', [
        'name' => 'sinpunto',
        'display_name' => 'Sin punto',
    ])->assertUnprocessable();

    $this->assertDatabaseMissing('permissions', ['name' => 'sinpunto']);
});

test('un administrador renombra la descripción de un permiso', function () {
    $permiso = Permission::where('name', 'users.read')->firstOrFail();

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->putJson("/permissions/{$permiso->id}", ['display_name' => 'Ver usuarios (nuevo)'])
        ->assertOk();

    $this->assertDatabaseHas('permissions', ['id' => $permiso->id, 'display_name' => 'Ver usuarios (nuevo)']);
});

test('actualizar un permiso inexistente responde 404', function () {
    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->putJson('/permissions/999999', ['display_name' => 'X'])
        ->assertNotFound();
});

test('eliminar un permiso sin asignar lo borra', function () {
    $permiso = Permission::create(['name' => 'temporal.borrar', 'display_name' => 'Temporal', 'resource' => 'temporal', 'action' => 'borrar']);

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->deleteJson("/permissions/{$permiso->id}")
        ->assertOk();

    expect(Permission::find($permiso->id))->toBeNull();
});

test('no se puede eliminar un permiso que está asignado a un rol', function () {
    $permiso = Permission::where('name', 'users.read')->firstOrFail();

    $this->withHeaders(rolesCabeceras(User::factory()->admin()->create()))
        ->deleteJson("/permissions/{$permiso->id}")
        ->assertUnprocessable();

    expect(Permission::find($permiso->id))->not->toBeNull();
});

test('eliminar un permiso sin permissions.delete responde 403', function () {
    $permiso = Permission::create(['name' => 'temporal.protegido', 'display_name' => 'Protegido', 'resource' => 'temporal', 'action' => 'protegido']);

    $this->withHeaders(rolesCabeceras(rolesUsuarioCon('permissions.read', 'permissions.delete')))
        ->deleteJson("/permissions/{$permiso->id}")
        ->assertForbidden();

    expect(Permission::find($permiso->id))->not->toBeNull();
});

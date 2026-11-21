<?php

use App\Models\Logs\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Huecos de /users que no cubren UserControllerTest, UserPermissionsTest ni
 * UserRolesEscalationTest: listado con filtros y orden, detalle ajeno, papelera,
 * actividad y retirada de roles. Un usuario por test: el guard JWT conserva el
 * usuario entre peticiones del mismo test.
 */

uses(RefreshDatabase::class);

function usuariosCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function usuariosCon(string ...$permisos): User
{
    $user = User::factory()->create();

    foreach ($permisos as $permiso) {
        $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));
    }

    return $user;
}

function usuariosConRol(string $rol, string $nombre): User
{
    $user = User::factory()->create(['name' => $nombre]);
    $user->roles()->attach(Role::where('name', $rol)->value('id'));

    return $user;
}

// ── listado: filtros y orden ────────────────────────────────────────────────

test('el listado de usuarios se ordena por nombre ascendente', function () {
    usuariosConRol('user', 'Zeta Prueba');
    usuariosConRol('user', 'Alfa Prueba');
    usuariosConRol('user', 'Medio Prueba');

    $nombres = collect($this->withHeaders(usuariosCabeceras(usuariosCon('users.read')))
        ->getJson('/users?sort=name&order=asc&per_page=500')
        ->assertOk()
        ->json('data'))->pluck('name')
        ->filter(fn ($n) => str_ends_with($n, 'Prueba'))
        ->values();

    expect($nombres->all())->toBe(['Alfa Prueba', 'Medio Prueba', 'Zeta Prueba']);
});

test('un campo de orden que no existe responde 400 invalid_query (lista blanca, ya no cae en silencio)', function () {
    $this->withHeaders(usuariosCabeceras(usuariosCon('users.read')))
        ->getJson('/users?sort=password&order=sinsentido')
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_query');
});

test('un order sin sentido con un campo de orden válido se ignora', function () {
    $this->withHeaders(usuariosCabeceras(usuariosCon('users.read')))
        ->getJson('/users?sort=name&order=sinsentido')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('el listado de usuarios filtra por rol', function () {
    $manager = usuariosConRol('manager', 'Gestora Filtro');
    $normal = usuariosConRol('user', 'Usuaria Filtro');

    $ids = collect($this->withHeaders(usuariosCabeceras(usuariosCon('users.read')))
        ->getJson('/users?role=manager&per_page=500')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($manager->id)->not->toContain($normal->id);
});

test('el listado de usuarios sin users.read responde 403', function () {
    $this->withHeaders(usuariosCabeceras(usuariosCon()))->getJson('/users')->assertForbidden();
});

// ── detalle ─────────────────────────────────────────────────────────────────

test('un usuario sin users.read no ve el detalle de otro usuario', function () {
    $otro = User::factory()->create();

    $this->withHeaders(usuariosCabeceras(usuariosCon()))->getJson("/users/{$otro->id}")
        ->assertForbidden();
});

test('un usuario ve su propio detalle sin users.read', function () {
    $yo = usuariosCon();

    $this->withHeaders(usuariosCabeceras($yo))->getJson("/users/{$yo->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $yo->id);
});

test('pedir el detalle de un usuario inexistente responde 404', function () {
    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))->getJson('/users/999999')
        ->assertNotFound();
});

// ── papelera: restaurar ─────────────────────────────────────────────────────

test('restaurar un usuario borrado lo devuelve a la base de datos', function () {
    $borrado = User::factory()->create();
    $borrado->delete();

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->postJson("/users/{$borrado->id}/restore")
        ->assertOk();

    expect(User::find($borrado->id))->not->toBeNull();
});

test('restaurar sin users.restore responde 403 y deja el usuario borrado', function () {
    $borrado = User::factory()->create();
    $borrado->delete();

    $this->withHeaders(usuariosCabeceras(usuariosCon('users.delete')))
        ->postJson("/users/{$borrado->id}/restore")
        ->assertForbidden();

    expect(User::find($borrado->id))->toBeNull();
});

test('restaurar un usuario que no está borrado responde 404', function () {
    $activo = User::factory()->create();

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->postJson("/users/{$activo->id}/restore")
        ->assertNotFound();
});

test('restaurar un id inexistente responde 404', function () {
    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->postJson('/users/999999/restore')
        ->assertNotFound();
});

// ── actividad ───────────────────────────────────────────────────────────────

test('un administrador ve la actividad de un usuario', function () {
    $objetivo = User::factory()->create();
    ActivityLog::factory()->create(['user_id' => $objetivo->id]);

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->getJson("/users/{$objetivo->id}/activity")
        ->assertOk()
        ->assertJsonPath('data.0.user_id', $objetivo->id);
});

test('un usuario ve su propia actividad sin users.read', function () {
    $yo = usuariosCon();
    ActivityLog::factory()->create(['user_id' => $yo->id]);

    $this->withHeaders(usuariosCabeceras($yo))->getJson("/users/{$yo->id}/activity")
        ->assertOk();
});

test('un usuario sin users.read no ve la actividad de otro', function () {
    $otro = User::factory()->create();

    $this->withHeaders(usuariosCabeceras(usuariosCon()))->getJson("/users/{$otro->id}/activity")
        ->assertForbidden();
});

test('la actividad de un usuario inexistente responde 404', function () {
    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->getJson('/users/999999/activity')
        ->assertNotFound();
});

// ── roles de usuario ────────────────────────────────────────────────────────

test('asignar roles con una lista vacía responde 422', function () {
    $objetivo = User::factory()->create();

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->postJson("/users/{$objetivo->id}/roles", ['role_ids' => [], 'reason' => 'prueba'])
        ->assertUnprocessable();
});

test('asignar roles a un usuario inexistente responde 404', function () {
    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->postJson('/users/999999/roles', ['role_ids' => [Role::where('name', 'user')->value('id')]])
        ->assertNotFound();
});

test('quitar un rol retira la asignación en la base de datos', function () {
    $objetivo = User::factory()->create();
    $rolUser = Role::where('name', 'user')->value('id');
    $objetivo->roles()->attach($rolUser);

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->deleteJson("/users/{$objetivo->id}/roles/{$rolUser}", ['reason' => 'prueba'])
        ->assertOk();

    expect(DB::table('model_has_roles')->where('model_id', $objetivo->id)->where('role_id', $rolUser)->exists())
        ->toBeFalse();
});

test('quitar un rol que no existe responde 404', function () {
    $objetivo = User::factory()->create();

    $this->withHeaders(usuariosCabeceras(User::factory()->admin()->create()))
        ->deleteJson("/users/{$objetivo->id}/roles/999999")
        ->assertNotFound();
});

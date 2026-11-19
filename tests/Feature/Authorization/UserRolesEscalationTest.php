<?php

use App\Exceptions\BusinessLogicException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function actingAsToken(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $role)->value('id'));

    return $user;
}

// ---------- LOG-01 ----------

test('un usuario no puede asignarse roles con PUT sobre su propio usuario', function () {
    $user = userWithRole('user');
    $adminRoleId = Role::where('name', 'admin')->value('id');

    $this->withHeaders(actingAsToken($user))
        ->putJson("/users/{$user->id}", ['role_ids' => [$adminRoleId]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_ids']);

    expect($user->fresh()->hasRole('admin'))->toBeFalse();
});

test('un usuario no puede cambiar su contraseña con PUT sobre su propio usuario', function () {
    $user = User::factory()->create();
    $hash = $user->password;

    $this->withHeaders(actingAsToken($user))
        ->putJson("/users/{$user->id}", ['password' => 'Password123!'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    expect($user->fresh()->password)->toBe($hash);
});

test('un usuario sin users.update no puede cambiar su email con PUT', function () {
    $user = User::factory()->create();
    $email = $user->email;

    $this->withHeaders(actingAsToken($user))
        ->putJson("/users/{$user->id}", ['email' => 'otro@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh()->email)->toBe($email);
});

test('un usuario puede editar los campos de su propio perfil con PUT', function () {
    $user = User::factory()->create();

    $this->withHeaders(actingAsToken($user))
        ->putJson("/users/{$user->id}", ['name' => 'Nombre Nuevo'])
        ->assertOk();

    expect($user->fresh()->name)->toBe('Nombre Nuevo');
});

test('un admin no puede asignar roles por PUT, solo por la ruta de roles', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $roleId = Role::where('name', 'editor')->value('id');

    $this->withHeaders(actingAsToken($admin))
        ->putJson("/users/{$target->id}", ['role_ids' => [$roleId]])
        ->assertStatus(422);

    $this->withHeaders(actingAsToken($admin))
        ->postJson("/users/{$target->id}/roles", ['role_ids' => [$roleId]])
        ->assertOk();

    expect($target->fresh()->hasRole('editor'))->toBeTrue();
});

// ---------- LOG-06 ----------

test('un manager no puede asignarse el rol admin', function () {
    $manager = userWithRole('manager');
    $adminRoleId = Role::where('name', 'admin')->value('id');

    $this->withHeaders(actingAsToken($manager))
        ->postJson("/users/{$manager->id}/roles", ['role_ids' => [$adminRoleId]])
        ->assertStatus(403);

    expect($manager->fresh()->hasRole('admin'))->toBeFalse();
});

test('asignar roles exige users.manage-roles y no basta users.update', function () {
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', 'users.update')->value('id'));
    $target = User::factory()->create();
    $roleId = Role::where('name', 'guest')->value('id');

    $this->withHeaders(actingAsToken($user))
        ->postJson("/users/{$target->id}/roles", ['role_ids' => [$roleId]])
        ->assertStatus(403);
});

test('un manager no puede asignar un rol con permisos que él no tiene', function () {
    $manager = userWithRole('manager');
    $target = User::factory()->create();
    // moderator tiene posts.moderate, que el manager no tiene
    $roleId = Role::where('name', 'moderator')->value('id');

    $this->withHeaders(actingAsToken($manager))
        ->postJson("/users/{$target->id}/roles", ['role_ids' => [$roleId]])
        ->assertStatus(403);

    expect($target->fresh()->hasRole('moderator'))->toBeFalse();
});

test('un manager puede asignar un rol con permisos que él también tiene', function () {
    $manager = userWithRole('manager');
    $target = User::factory()->create();
    $roleId = Role::where('name', 'guest')->value('id');

    $this->withHeaders(actingAsToken($manager))
        ->postJson("/users/{$target->id}/roles", ['role_ids' => [$roleId]])
        ->assertOk();
});

test('un manager no puede editar a un admin ni cambiarle la contraseña', function () {
    $manager = userWithRole('manager');
    $admin = User::factory()->admin()->create();
    $hash = $admin->password;

    $this->withHeaders(actingAsToken($manager))
        ->putJson("/users/{$admin->id}", ['password' => 'Password123!'])
        ->assertStatus(403);

    expect($admin->fresh()->password)->toBe($hash);
});

test('un manager no puede quitarle roles a un admin', function () {
    $manager = userWithRole('manager');
    $admin = User::factory()->admin()->create();
    $adminRoleId = Role::where('name', 'admin')->value('id');

    $this->withHeaders(actingAsToken($manager))
        ->deleteJson("/users/{$admin->id}/roles/{$adminRoleId}")
        ->assertStatus(403);
});

test('un manager no puede dar permisos a un rol al actualizarlo sin roles.manage-permissions', function () {
    $manager = userWithRole('manager');
    $role = Role::where('name', 'guest')->first();
    $before = $role->permissions()->count();
    $allIds = Permission::pluck('id')->all();

    $this->withHeaders(actingAsToken($manager))
        ->putJson("/roles/{$role->id}", ['display_name' => 'Invitado', 'permission_ids' => $allIds])
        ->assertStatus(403);

    expect($role->permissions()->count())->toBe($before);
});

test('un manager no puede crear un rol con permisos sin roles.manage-permissions', function () {
    $manager = userWithRole('manager');

    $this->withHeaders(actingAsToken($manager))
        ->postJson('/roles', [
            'name' => 'nuevo-rol',
            'display_name' => 'Nuevo',
            'permission_ids' => Permission::pluck('id')->all(),
        ])
        ->assertStatus(403);

    expect(Role::where('name', 'nuevo-rol')->exists())->toBeFalse();
});

test('quien tiene roles.manage-permissions no concede permisos que no tiene', function () {
    $user = userWithRole('manager');
    $user->permissions()->attach(Permission::where('name', 'roles.manage-permissions')->value('id'));
    $role = Role::where('name', 'guest')->first();
    $forbidden = Permission::where('name', 'system.backup')->value('id');

    $this->withHeaders(actingAsToken($user))
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => [$forbidden]])
        ->assertStatus(403);

    expect($role->permissions()->where('permissions.id', $forbidden)->exists())->toBeFalse();
});

test('un admin sí puede crear un rol con permisos', function () {
    $admin = User::factory()->admin()->create();

    $this->withHeaders(actingAsToken($admin))
        ->postJson('/roles', [
            'name' => 'nuevo-rol',
            'display_name' => 'Nuevo',
            'permission_ids' => [Permission::where('name', 'posts.read')->value('id')],
        ])
        ->assertStatus(201);

    expect(Role::where('name', 'nuevo-rol')->first()->permissions()->count())->toBe(1);
});

// ---------- LOG-20 ----------

test('el último admin no puede quitarse el rol admin', function () {
    $admin = User::factory()->admin()->create();
    $userRoleId = Role::where('name', 'user')->value('id');

    $this->withHeaders(actingAsToken($admin))
        ->postJson("/users/{$admin->id}/roles", ['role_ids' => [$userRoleId]])
        ->assertStatus(422);

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('el último admin con dos roles no puede quitarse el rol admin', function () {
    $admin = User::factory()->admin()->create();
    $admin->roles()->attach(Role::where('name', 'user')->value('id'));
    $adminRoleId = Role::where('name', 'admin')->value('id');

    $this->withHeaders(actingAsToken($admin))
        ->deleteJson("/users/{$admin->id}/roles/{$adminRoleId}")
        ->assertStatus(422);

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('un admin puede quitarse el rol admin si queda otro admin', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();
    $userRoleId = Role::where('name', 'user')->value('id');

    $this->withHeaders(actingAsToken($admin))
        ->postJson("/users/{$admin->id}/roles", ['role_ids' => [$userRoleId]])
        ->assertOk();

    expect($admin->fresh()->hasRole('admin'))->toBeFalse();
});

test('no se puede eliminar al último admin', function () {
    $lastAdmin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    // Con dos admins, borrar a uno funciona; el que queda ya no se puede borrar
    $this->withHeaders(actingAsToken($other))->deleteJson("/users/{$lastAdmin->id}")->assertOk();

    $service = app(UserService::class);
    expect(fn () => $service->delete((string) $other->id))
        ->toThrow(BusinessLogicException::class);
    expect(User::find($other->id))->not->toBeNull();
});

test('no se puede borrar ni renombrar el rol admin', function () {
    $admin = User::factory()->admin()->create();
    $adminRole = Role::where('name', 'admin')->first();

    $this->withHeaders(actingAsToken($admin))
        ->deleteJson("/roles/{$adminRole->id}")
        ->assertStatus(403);

    $this->withHeaders(actingAsToken($admin))
        ->putJson("/roles/{$adminRole->id}", ['name' => 'superadmin'])
        ->assertStatus(422);

    expect(Role::where('name', 'admin')->exists())->toBeTrue();
});

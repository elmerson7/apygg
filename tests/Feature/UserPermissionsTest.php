<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed roles y permisos base
    $this->seed([
        \Database\Seeders\RoleSeeder::class,
        \Database\Seeders\PermissionSeeder::class,
    ]);
});

test('usuario con rol admin tiene todos los permisos', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->hasPermission('users.create'))->toBeTrue()
        ->and($admin->hasPermission('users.read'))->toBeTrue()
        ->and($admin->hasPermission('users.update'))->toBeTrue()
        ->and($admin->hasPermission('users.delete'))->toBeTrue()
        ->and($admin->hasPermission('roles.create'))->toBeTrue();
});

test('usuario con rol user tiene permisos básicos', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'user')->first();
    $user->roles()->attach($userRole->id);

    expect($user->hasPermission('users.read'))->toBeTrue()
        ->and($user->hasPermission('posts.create'))->toBeTrue()
        ->and($user->hasPermission('posts.read'))->toBeTrue()
        ->and($user->hasPermission('users.create'))->toBeFalse()
        ->and($user->hasPermission('users.delete'))->toBeFalse();
});

test('usuario con rol guest solo tiene permisos de lectura', function () {
    $guest = User::factory()->create();
    $guestRole = Role::where('name', 'guest')->first();
    $guest->roles()->attach($guestRole->id);

    expect($guest->hasPermission('users.read'))->toBeTrue()
        ->and($guest->hasPermission('posts.read'))->toBeTrue()
        ->and($guest->hasPermission('users.create'))->toBeFalse()
        ->and($guest->hasPermission('posts.create'))->toBeFalse();
});

test('usuario puede tener permisos directos además de roles', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'user')->first();
    $user->roles()->attach($userRole->id);

    // Asignar permiso directo
    $usersCreatePermission = Permission::where('name', 'users.create')->first();
    $user->permissions()->attach($usersCreatePermission->id);

    expect($user->hasPermission('users.create'))->toBeTrue()
        ->and($user->hasPermission('posts.create'))->toBeTrue(); // Del rol
});

test('usuario puede tener múltiples roles', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'user')->first();
    $editorRole = Role::where('name', 'editor')->first();

    $user->roles()->attach([$userRole->id, $editorRole->id]);

    expect($user->hasRole('user'))->toBeTrue()
        ->and($user->hasRole('editor'))->toBeTrue()
        ->and($user->hasAnyRole(['user', 'admin']))->toBeTrue()
        ->and($user->hasAnyRole(['moderator', 'admin']))->toBeFalse();
});

test('usuario puede verificar si tiene cualquier permiso de una lista', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'user')->first();
    $user->roles()->attach($userRole->id);

    // Asignar permiso directo adicional
    $usersCreatePermission = Permission::where('name', 'users.create')->first();
    $user->permissions()->attach($usersCreatePermission->id);

    expect($user->hasAnyPermission(['users.create', 'users.delete']))->toBeTrue()
        ->and($user->hasAnyPermission(['users.delete', 'roles.create']))->toBeFalse();
});

test('usuario puede verificar si tiene todos los permisos de una lista', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasAllPermissions(['users.create', 'users.read', 'users.update']))->toBeTrue()
        ->and($admin->hasAllPermissions(['users.create', 'nonexistent.permission']))->toBeFalse();
});

test('endpoint requiere permiso users.read para listar usuarios', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/users');

    // Usuario sin permiso users.read no puede listar
    $response->assertStatus(403);
});

test('endpoint requiere permiso users.create para crear usuarios', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $userData = [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'Password123!',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/users', $userData);

    // Usuario sin permiso users.create no puede crear
    $response->assertStatus(403);
});

test('endpoint requiere permiso users.delete para eliminar usuarios', function () {
    $user = User::factory()->create();
    $targetUser = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/users/{$targetUser->id}");

    // Usuario sin permiso users.delete no puede eliminar
    $response->assertStatus(403);
});

test('usuario puede actualizar su propio perfil sin permiso users.update', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$user->id}", $updateData);

    // Usuario puede actualizar su propio perfil
    $response->assertStatus(200);
});

test('usuario no puede actualizar otro usuario sin permiso users.update', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $token = JWTAuth::fromUser($user1);

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$user2->id}", $updateData);

    // Usuario sin permiso users.update no puede actualizar otros usuarios
    $response->assertStatus(403);
});

test('usuario con permiso users.update puede actualizar otros usuarios', function () {
    $user = User::factory()->create();
    $usersUpdatePermission = Permission::where('name', 'users.update')->first();
    $user->permissions()->attach($usersUpdatePermission->id);

    $targetUser = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$targetUser->id}", $updateData);

    // Usuario con permiso users.update puede actualizar otros usuarios
    $response->assertStatus(200);
});

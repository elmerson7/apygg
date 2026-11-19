<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed roles y permisos base
    $this->seed([
        RoleSeeder::class,
        PermissionSeeder::class,
    ]);
});

test('usuario con rol admin tiene todos los permisos', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->can('users.create'))->toBeTrue()
        ->and($admin->can('users.read'))->toBeTrue()
        ->and($admin->can('users.update'))->toBeTrue()
        ->and($admin->can('users.delete'))->toBeTrue()
        ->and($admin->can('roles.create'))->toBeTrue();
});

test('el admin es super admin: pasa incluso un permiso que no está asignado ni existe', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    expect($admin->can('algo.que-no-existe'))->toBeTrue()
        ->and($user->can('algo.que-no-existe'))->toBeFalse();
});

test('usuario con rol user no tiene permisos de administración', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    expect($user->can('users.read'))->toBeFalse()
        ->and($user->can('roles.read'))->toBeFalse()
        ->and($user->can('permissions.read'))->toBeFalse()
        ->and($user->can('users.create'))->toBeFalse()
        ->and($user->can('users.delete'))->toBeFalse()
        ->and($user->getAllPermissions())->toBeEmpty();
});

test('usuario con rol manager gestiona usuarios y roles pero no los borra', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect($manager->can('users.read'))->toBeTrue()
        ->and($manager->can('roles.read'))->toBeTrue()
        ->and($manager->can('users.create'))->toBeTrue()
        ->and($manager->can('users.delete'))->toBeFalse()
        ->and($manager->can('roles.delete'))->toBeFalse()
        ->and($manager->can('roles.manage-permissions'))->toBeFalse();
});

test('usuario puede tener permisos directos además de roles', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $user->givePermissionTo('users.delete');

    expect($user->can('users.delete'))->toBeTrue() // Directo
        ->and($user->can('users.read'))->toBeTrue() // Del rol
        ->and($user->hasDirectPermission('users.read'))->toBeFalse();
});

test('usuario puede tener múltiples roles', function () {
    $user = User::factory()->create();
    $user->assignRole(['user', 'manager']);

    expect($user->hasRole('user'))->toBeTrue()
        ->and($user->hasRole('manager'))->toBeTrue()
        ->and($user->hasAnyRole(['user', 'admin']))->toBeTrue()
        ->and($user->hasAnyRole(['moderator', 'admin']))->toBeFalse();
});

test('usuario puede verificar si tiene cualquier permiso de una lista', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $user->givePermissionTo('users.create');

    expect($user->hasAnyPermission(['users.create', 'users.delete']))->toBeTrue()
        ->and($user->hasAnyPermission(['users.delete', 'roles.create']))->toBeFalse();
});

test('usuario puede verificar si tiene todos los permisos de una lista', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect($manager->hasAllPermissions(['users.create', 'users.read', 'users.update']))->toBeTrue()
        ->and($manager->hasAllPermissions(['users.create', 'nonexistent.permission']))->toBeFalse();
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
    $user->givePermissionTo('users.update');

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

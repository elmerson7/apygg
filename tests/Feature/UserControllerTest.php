<?php

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

test('puede listar usuarios con autenticación y permiso', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    User::factory()->count(3)->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/users');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'email'],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
            ],
        ]);
});

test('no puede listar usuarios sin autenticación', function () {
    $response = $this->getJson('/users');

    $response->assertStatus(401);
});

test('no puede listar usuarios sin permiso users.read', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/users');

    $response->assertStatus(403);
});

test('puede crear un usuario con permiso users.create', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $userData = [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'Password123!',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/users', $userData);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'name', 'email'],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'newuser@example.com',
        'name' => 'New User',
    ]);
});

test('no puede crear usuario sin permiso users.create', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $userData = [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'Password123!',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/users', $userData);

    $response->assertStatus(403);
});

test('valida datos requeridos al crear usuario', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/users', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('puede ver detalles de un usuario', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    // Usuario puede ver su propio perfil sin permiso users.read
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'name', 'email'],
        ])
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
});

test('puede actualizar su propio usuario', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$user->id}", $updateData);

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'name' => 'Updated Name',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated Name',
    ]);
});

test('puede actualizar otro usuario con permiso users.update', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create();

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$targetUser->id}", $updateData);

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $targetUser->id,
        'name' => 'Updated Name',
    ]);
});

test('no puede actualizar otro usuario sin permiso', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $token = JWTAuth::fromUser($user1);

    $updateData = [
        'name' => 'Updated Name',
    ];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/users/{$user2->id}", $updateData);

    $response->assertStatus(403);
});

test('puede eliminar un usuario con permiso users.delete', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/users/{$targetUser->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('users', [
        'id' => $targetUser->id,
    ]);
});

test('no puede eliminar usuario sin permiso users.delete', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $targetUser = User::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/users/{$targetUser->id}");

    $response->assertStatus(403);
});

test('puede restaurar un usuario eliminado con permiso users.restore', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create();
    $targetUser->delete();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/users/{$targetUser->id}/restore");

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $targetUser->id,
        'deleted_at' => null,
    ]);
});

test('puede asignar roles a un usuario con permiso users.update', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create();
    $managerRole = Role::where('name', 'manager')->first();
    $editorRole = Role::where('name', 'editor')->first();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/users/{$targetUser->id}/roles", [
            'role_ids' => [$managerRole->id, $editorRole->id],
        ]);

    $response->assertStatus(200);

    $targetUser->refresh();
    expect($targetUser->roles)->toHaveCount(2)
        ->and($targetUser->hasRole('manager'))->toBeTrue()
        ->and($targetUser->hasRole('editor'))->toBeTrue();
});

test('puede remover un rol de un usuario con permiso users.update', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create();
    $managerRole = Role::where('name', 'manager')->first();
    $editorRole = Role::where('name', 'editor')->first();

    $targetUser->roles()->attach([$managerRole->id, $editorRole->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/users/{$targetUser->id}/roles/{$managerRole->id}");

    $response->assertStatus(200);

    $targetUser->refresh();
    expect($targetUser->roles)->toHaveCount(1)
        ->and($targetUser->hasRole('manager'))->toBeFalse()
        ->and($targetUser->hasRole('editor'))->toBeTrue();
});

test('puede obtener actividad de un usuario', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/users/{$user->id}/activity");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
        ]);
});

<?php

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userService = new UserService;

    // Seed roles y permisos base
    $this->seed([
        \Database\Seeders\RoleSeeder::class,
        \Database\Seeders\PermissionSeeder::class,
    ]);
});

test('puede crear un usuario', function () {
    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ];

    $user = $this->userService->create($userData);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('test@example.com')
        ->and($user->name)->toBe('Test User')
        ->and($user->hasRole('user'))->toBeTrue();
});

test('asigna rol user por defecto si no se especifica', function () {
    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ];

    $user = $this->userService->create($userData);

    expect($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->name)->toBe('user');
});

test('puede crear usuario con roles específicos', function () {
    $adminRole = Role::where('name', 'admin')->first();
    $managerRole = Role::where('name', 'manager')->first();

    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ];

    $user = $this->userService->create($userData, [$adminRole->id, $managerRole->id]);

    expect($user->roles)->toHaveCount(2)
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and($user->hasRole('manager'))->toBeTrue();
});

test('lanza excepción si el email ya existe', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ];

    expect(fn () => $this->userService->create($userData))
        ->toThrow(\InvalidArgumentException::class, "El email 'test@example.com' ya está en uso");
});

test('puede actualizar un usuario', function () {
    $user = User::factory()->create();

    $updatedData = [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ];

    $updatedUser = $this->userService->update($user->id, $updatedData);

    expect($updatedUser->name)->toBe('Updated Name')
        ->and($updatedUser->email)->toBe('updated@example.com');
});

test('lanza excepción si el email actualizado ya existe', function () {
    $user1 = User::factory()->create(['email' => 'user1@example.com']);
    $user2 = User::factory()->create(['email' => 'user2@example.com']);

    expect(fn () => $this->userService->update($user1->id, ['email' => 'user2@example.com']))
        ->toThrow(\InvalidArgumentException::class, "El email 'user2@example.com' ya está en uso");
});

test('puede eliminar un usuario (soft delete)', function () {
    $user = User::factory()->create();

    $this->userService->delete($user->id);

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

test('puede restaurar un usuario eliminado', function () {
    $user = User::factory()->create();
    $this->userService->delete($user->id);

    $restoredUser = $this->userService->restore($user->id);

    expect($restoredUser->trashed())->toBeFalse()
        ->and(User::find($user->id))->not->toBeNull();
});

test('puede asignar roles a un usuario', function () {
    $user = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->first();
    $managerRole = Role::where('name', 'manager')->first();

    $this->userService->assignRoles($user->id, [$adminRole->id, $managerRole->id]);

    $user->refresh();
    expect($user->roles)->toHaveCount(2)
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and($user->hasRole('manager'))->toBeTrue();
});

test('puede remover un rol de un usuario', function () {
    $user = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->first();
    $managerRole = Role::where('name', 'manager')->first();

    $user->roles()->attach([$adminRole->id, $managerRole->id]);

    $this->userService->removeRole($user->id, $adminRole->id);

    $user->refresh();
    expect($user->roles)->toHaveCount(1)
        ->and($user->hasRole('admin'))->toBeFalse()
        ->and($user->hasRole('manager'))->toBeTrue();
});

test('puede buscar usuarios con filtros', function () {
    User::factory()->create(['name' => 'John Doe', 'email' => 'john.doe@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane.smith@example.com']);
    User::factory()->create(['name' => 'Bob Johnson', 'email' => 'bob.johnson@example.com']);

    $result = $this->userService->list([
        'search' => 'John Doe',
    ]);

    expect($result->items())->toHaveCount(1)
        ->and($result->items()[0]->name)->toBe('John Doe');
});

test('puede buscar usuarios por rol', function () {
    $adminRole = Role::where('name', 'admin')->first();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $user1->roles()->attach($adminRole->id);

    $result = $this->userService->list([
        'role' => 'admin',
    ]);

    expect($result->items())->toHaveCount(1)
        ->and($result->items()[0]->id)->toBe($user1->id);
});

test('puede obtener un usuario por ID', function () {
    $user = User::factory()->create();

    $foundUser = $this->userService->find($user->id);

    expect($foundUser->id)->toBe($user->id)
        ->and($foundUser->email)->toBe($user->email);
});

test('lanza excepción si el usuario no existe al buscar', function () {
    // Usar un UUID válido que no existe en la BD
    $nonExistentUuid = \Illuminate\Support\Str::uuid()->toString();

    expect(fn () => $this->userService->find($nonExistentUuid))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function logsHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('un usuario sin permiso no puede leer los logs', function (string $type) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(logsHeaders($user))->getJson("/admin/logs/{$type}")->assertStatus(403);
})->with(['activity', 'api', 'security']);

test('un usuario sin permiso no puede borrar los logs', function (string $type) {
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', 'system.logs')->value('id'));

    // leer sí, borrar no: son permisos distintos
    $this->withHeaders(logsHeaders($user))->deleteJson("/admin/logs/{$type}")->assertStatus(403);
})->with(['activity', 'api', 'security']);

test('quien tiene system.logs lee los logs', function () {
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', 'system.logs')->value('id'));

    $this->withHeaders(logsHeaders($user))->getJson('/admin/logs/api')->assertOk();
});

test('quien tiene system.logs-delete borra los logs', function () {
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', 'system.logs-delete')->value('id'));

    $this->withHeaders(logsHeaders($user))->deleteJson('/admin/logs/security')->assertOk();
});

test('el admin accede a los logs', function () {
    $admin = User::factory()->admin()->create();

    $this->withHeaders(logsHeaders($admin))->getJson('/admin/logs/activity')->assertOk();
});

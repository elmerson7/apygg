<?php

use App\Http\Resources\Users\UserSearchResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function searchHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function userWithPermission(string $permission): User
{
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', $permission)->value('id'));

    return $user;
}

test('la búsqueda rechaza a quien no tiene users.read', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(searchHeaders($user))->getJson('/search?q=ab')->assertStatus(403);
});

test('la búsqueda rechaza el modelo users a quien no tiene users.read aunque lo pida explícito', function () {
    $user = User::factory()->create();

    $this->withHeaders(searchHeaders($user))->getJson('/search?q=ab&models[]=users')->assertStatus(403);
});

test('la búsqueda rechaza filtros fuera de la lista blanca', function (string $field) {
    $user = userWithPermission('users.read');

    $this->withHeaders(searchHeaders($user))
        ->getJson("/search?q=ab&filters[users][{$field}]=true")
        ->assertStatus(422);
})->with(['is_admin', 'identity_document', 'email', 'roles', 'role_ids']);

test('la búsqueda rechaza valores de filtro con comillas', function () {
    $user = userWithPermission('users.read');

    $this->withHeaders(searchHeaders($user))
        ->getJson('/search?q=ab&filters[users][timezone]='.urlencode('x" OR is_admin = true'))
        ->assertStatus(422);
});

test('la búsqueda acepta filtros de la lista blanca con users.read', function () {
    $user = userWithPermission('users.read');

    // Con el driver de Scout nulo de los tests la búsqueda real no devuelve datos;
    // aquí solo se comprueba que ni la autorización ni la validación la rechazan.
    $status = $this->withHeaders(searchHeaders($user))
        ->getJson('/search?q=ab&filters[users][timezone]=UTC')
        ->status();

    expect($status)->not->toBeIn([401, 403, 422]);
});

test('el resultado de usuarios no incluye documento, email ni roles para quien solo lee', function () {
    $viewer = userWithPermission('users.read');
    $resource = new UserSearchResource(
        User::factory()->create(['identity_document' => '12345678'])
    );
    $request = Request::create('/search');
    $request->setUserResolver(fn () => $viewer);

    $data = $resource->toArray($request);

    expect($data)->toHaveKeys(['id', 'name', 'username'])
        ->and($data)->not->toHaveKeys(['email', 'identity_document', 'roles']);
});

test('el email solo se muestra a quien administra usuarios y el documento nunca', function () {
    $viewer = userWithPermission('users.update');
    $target = User::factory()->create(['identity_document' => '12345678']);
    $request = Request::create('/search');
    $request->setUserResolver(fn () => $viewer);

    $data = (new UserSearchResource($target))->toArray($request);

    expect($data['email'])->toBe($target->email)
        ->and($data)->not->toHaveKey('identity_document');
});

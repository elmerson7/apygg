<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Simula un worker de Octane cuyo guard por defecto no es el de la API.
    config()->set('auth.defaults.guard', 'web');
    auth()->shouldUse('web');
});

test('permission autoriza al usuario autenticado en el guard api aunque el guard por defecto sea web', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin, 'api');
    auth()->shouldUse('web');

    $response = (new PermissionMiddleware)->handle(
        Request::create('/users', 'GET'),
        fn () => new Response('ok'),
        'users.read',
        'api'
    );

    expect($response->getStatusCode())->toBe(200);
});

test('permission rechaza si solo hay sesión en el guard web', function () {
    $this->actingAs(User::factory()->admin()->create(), 'web');

    expect(fn () => (new PermissionMiddleware)->handle(
        Request::create('/users', 'GET'),
        fn () => new Response('ok'),
        'users.read',
        'api'
    ))->toThrow(UnauthorizedException::class);
});

test('permission rechaza al usuario api sin el permiso', function () {
    $this->actingAs(User::factory()->create(), 'api');
    auth()->shouldUse('web');

    expect(fn () => (new PermissionMiddleware)->handle(
        Request::create('/users', 'DELETE'),
        fn () => new Response('ok'),
        'inexistente.permiso',
        'api'
    ))->toThrow(UnauthorizedException::class);
});

test('role autoriza al admin autenticado en el guard api aunque el guard por defecto sea web', function () {
    $this->actingAs(User::factory()->admin()->create(), 'api');
    auth()->shouldUse('web');

    $response = (new RoleMiddleware)->handle(
        Request::create('/admin/x', 'GET'),
        fn () => new Response('ok'),
        'admin',
        'api'
    );

    expect($response->getStatusCode())->toBe(200);
});

test('role rechaza si el usuario api no tiene el rol', function () {
    $this->actingAs(User::factory()->create(), 'api');
    auth()->shouldUse('web');

    expect(fn () => (new RoleMiddleware)->handle(
        Request::create('/admin/x', 'GET'),
        fn () => new Response('ok'),
        'superadmin',
        'api'
    ))->toThrow(UnauthorizedException::class);
});

test('la denegación del middleware sale con el formato RFC 7807 del proyecto', function () {
    $token = JWTAuth::fromUser(User::factory()->create());

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/users')
        ->assertStatus(403)
        ->assertJsonStructure(['type', 'title', 'status'])
        ->assertJson(['status' => 403, 'type' => 'forbidden'])
        ->assertJsonPath('required_permissions.0', 'users.read');
});

test('sin token la ruta protegida responde 401', function () {
    $this->getJson('/users')->assertStatus(401);
});

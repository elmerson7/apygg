<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Simula un worker de Octane cuyo guard por defecto no es el de la API.
    config()->set('auth.defaults.guard', 'web');
    auth()->shouldUse('web');
});

test('CheckPermission autoriza al usuario autenticado en el guard api aunque el guard por defecto sea web', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin, 'api');
    auth()->shouldUse('web');

    $response = (new CheckPermission)->handle(
        Request::create('/users', 'GET'),
        fn () => new Response('ok'),
        'users.read'
    );

    expect($response->getStatusCode())->toBe(200);
});

test('CheckPermission responde 401 si solo hay sesión en el guard web', function () {
    $this->actingAs(User::factory()->admin()->create(), 'web');

    $response = (new CheckPermission)->handle(
        Request::create('/users', 'GET'),
        fn () => new Response('ok'),
        'users.read'
    );

    expect($response->getStatusCode())->toBe(401);
});

test('CheckPermission responde 403 al usuario api sin el permiso', function () {
    $this->actingAs(User::factory()->create(), 'api');
    auth()->shouldUse('web');

    $response = (new CheckPermission)->handle(
        Request::create('/users', 'DELETE'),
        fn () => new Response('ok'),
        'inexistente.permiso'
    );

    expect($response->getStatusCode())->toBe(403);
});

test('CheckRole autoriza al admin autenticado en el guard api aunque el guard por defecto sea web', function () {
    $this->actingAs(User::factory()->admin()->create(), 'api');
    auth()->shouldUse('web');

    $response = (new CheckRole)->handle(
        Request::create('/admin/x', 'GET'),
        fn () => new Response('ok'),
        'admin'
    );

    expect($response->getStatusCode())->toBe(200);
});

test('CheckRole responde 403 si el usuario api no tiene el rol', function () {
    $this->actingAs(User::factory()->create(), 'api');
    auth()->shouldUse('web');

    $response = (new CheckRole)->handle(
        Request::create('/admin/x', 'GET'),
        fn () => new Response('ok'),
        'superadmin'
    );

    expect($response->getStatusCode())->toBe(403);
});

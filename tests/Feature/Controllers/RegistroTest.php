<?php

use App\Events\UserCreated;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function datosRegistro(array $extra = []): array
{
    return array_merge([
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@example.com',
        'password' => 'Clave-Segura-123!',
        'password_confirmation' => 'Clave-Segura-123!',
    ], $extra);
}

test('el registro crea el usuario con su perfil y le asigna el rol por defecto', function () {
    $this->postJson('/auth/register', datosRegistro())
        ->assertCreated()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

    $user = User::where('email', 'nuevo@example.com')->firstOrFail();

    expect($user->hasRole(config('auth.default_role')))->toBeTrue()
        ->and($user->profile)->not->toBeNull();
});

test('el registro respeta el rol por defecto configurado', function () {
    config(['auth.default_role' => 'admin']);

    $this->postJson('/auth/register', datosRegistro())->assertCreated();

    expect(User::where('email', 'nuevo@example.com')->first()->hasRole('admin'))->toBeTrue();
});

test('el registro dispara el evento UserCreated', function () {
    Event::fake([UserCreated::class]);

    $this->postJson('/auth/register', datosRegistro())->assertCreated();

    Event::assertDispatched(UserCreated::class, fn (UserCreated $e) => $e->user->email === 'nuevo@example.com');
});

test('el registro guarda el email en minusculas', function () {
    $this->postJson('/auth/register', datosRegistro(['email' => 'Nuevo.Mayus@Example.COM']))->assertCreated();

    expect(User::where('email', 'nuevo.mayus@example.com')->exists())->toBeTrue();
});

test('el registro rechaza un email repetido con otra capitalizacion', function () {
    User::factory()->create(['email' => 'duplicado@example.com']);

    $this->postJson('/auth/register', datosRegistro(['email' => 'Duplicado@Example.com']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(User::whereRaw('lower(email) = ?', ['duplicado@example.com'])->count())->toBe(1);
});

test('el registro con datos invalidos responde 422 y no crea nada', function () {
    $this->postJson('/auth/register', datosRegistro(['password_confirmation' => 'distinta']))
        ->assertUnprocessable();

    expect(User::where('email', 'nuevo@example.com')->exists())->toBeFalse();
});

test('el login acepta el email con otra capitalizacion', function () {
    $this->postJson('/auth/register', datosRegistro())->assertCreated();

    $this->postJson('/auth/login', ['login' => 'NUEVO@Example.com', 'password' => 'Clave-Segura-123!'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token']]);
});

test('el login con contrasena incorrecta sigue siendo rechazado', function () {
    $this->postJson('/auth/register', datosRegistro())->assertCreated();

    $this->postJson('/auth/login', ['login' => 'nuevo@example.com', 'password' => 'incorrecta'])
        ->assertUnauthorized();
});

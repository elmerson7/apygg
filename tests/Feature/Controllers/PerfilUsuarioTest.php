<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function cabeceraPerfil(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('un usuario autenticado ve su propio perfil', function () {
    $user = User::factory()->create(['name' => 'Ana Perez', 'username' => 'ana']);
    $user->profile()->create(['first_name' => 'Ana', 'last_name' => 'Perez', 'city' => 'Lima']);

    $this->withHeaders(cabeceraPerfil($user))->getJson('/user/profile')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.username', 'ana')
        ->assertJsonPath('data.first_name', 'Ana')
        ->assertJsonPath('data.city', 'Lima')
        ->assertJsonMissingPath('data.password');
});

test('ver el perfil funciona aunque el usuario no tenga fila de perfil', function () {
    $user = User::factory()->create();

    $this->withHeaders(cabeceraPerfil($user))->getJson('/user/profile')
        ->assertOk()
        ->assertJsonPath('data.first_name', null);
});

test('ver el perfil sin autenticar responde 401', function () {
    $this->getJson('/user/profile')->assertUnauthorized();
});

test('un usuario edita sus campos de perfil', function () {
    $user = User::factory()->create(['username' => 'viejo']);

    $this->withHeaders(cabeceraPerfil($user))->putJson('/user/profile', [
        'first_name' => 'Luis',
        'last_name' => 'Gomez',
        'username' => 'luis',
        'timezone' => 'America/Lima',
        'phone' => '999888777',
        'bio' => 'Hola',
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Luis')
        ->assertJsonPath('data.name', 'Luis Gomez')
        ->assertJsonPath('data.username', 'luis')
        ->assertJsonPath('data.phone', '999888777');

    $user->refresh();
    expect($user->username)->toBe('luis')
        ->and($user->timezone)->toBe('America/Lima')
        ->and($user->profile->last_name)->toBe('Gomez');
});

test('editar el perfil no permite cambiar email, contrasena ni roles', function (string $campo, mixed $valor) {
    $user = User::factory()->create(['email' => 'original@example.com']);
    $hashOriginal = $user->password;

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/profile', [$campo => $valor])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($campo);

    $user->refresh();
    expect($user->email)->toBe('original@example.com')
        ->and($user->password)->toBe($hashOriginal)
        ->and($user->roles)->toHaveCount(0);
})->with([
    'email' => ['email', 'otro@example.com'],
    'password' => ['password', 'NuevaClave123!'],
    'role_ids' => ['role_ids', [1]],
]);

test('editar el perfil rechaza un username ya usado por otro usuario', function () {
    User::factory()->create(['username' => 'ocupado']);
    $user = User::factory()->create();

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/profile', ['username' => 'ocupado'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('editar el perfil permite conservar el propio username', function () {
    $user = User::factory()->create(['username' => 'mio']);

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/profile', ['username' => 'mio', 'city' => 'Cusco'])
        ->assertOk()
        ->assertJsonPath('data.city', 'Cusco');
});

test('editar el perfil sin autenticar responde 401', function () {
    $this->putJson('/user/profile', ['city' => 'Cusco'])->assertUnauthorized();
});

test('editar el perfil solo toca al usuario en sesion', function () {
    $otro = User::factory()->create(['name' => 'Otro Usuario']);
    $user = User::factory()->create();

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/profile', ['name' => 'Cambiado', 'id' => $otro->id])
        ->assertOk();

    expect($otro->fresh()->name)->toBe('Otro Usuario')
        ->and($user->fresh()->name)->toBe('Cambiado');
});

test('un usuario actualiza sus preferencias con un Form Request valido', function () {
    $user = User::factory()->create();

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/preferences', ['theme' => 'dark', 'lang' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.theme', 'dark');

    $this->withHeaders(cabeceraPerfil($user))
        ->putJson('/user/preferences', ['theme' => 'morado'])
        ->assertUnprocessable();
});

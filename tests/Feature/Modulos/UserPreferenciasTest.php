<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Huecos de routes/api/user.php: las preferencias propias se leen (GET) y no
 * aparecen en ninguna otra petición. PerfilUsuarioTest ya cubre la escritura.
 */

uses(RefreshDatabase::class);

function preferenciasCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('leer las preferencias sin token responde 401', function () {
    $this->getJson('/user/preferences')->assertUnauthorized();
});

test('un usuario lee sus preferencias tras guardarlas', function () {
    $user = User::factory()->create();

    $this->withHeaders(preferenciasCabeceras($user))
        ->putJson('/user/preferences', ['theme' => 'dark', 'lang' => 'es'])
        ->assertOk();

    // El guard JWT conserva el usuario de la petición anterior dentro del mismo test:
    // se olvidan los guards para que la lectura cargue las preferencias ya guardadas.
    $this->app['auth']->forgetGuards();

    $this->withHeaders(preferenciasCabeceras($user))->getJson('/user/preferences')
        ->assertOk()
        ->assertJsonPath('data.theme', 'dark');
});

test('las preferencias de un usuario no se ven desde otra cuenta', function () {
    $ana = User::factory()->create();
    $luis = User::factory()->create();

    $this->withHeaders(preferenciasCabeceras($ana))
        ->putJson('/user/preferences', ['theme' => 'dark', 'lang' => 'es'])
        ->assertOk();

    $this->withHeaders(preferenciasCabeceras($luis))->getJson('/user/preferences')
        ->assertOk()
        ->assertJsonMissing(['theme' => 'dark']);
});

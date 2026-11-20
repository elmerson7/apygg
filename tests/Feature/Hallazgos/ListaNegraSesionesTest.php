<?php

use App\Models\Blacklist;
use App\Models\Permission;
use App\Models\User;
use App\Services\ApiKeyService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;

/*
 * Un bloqueo corta las sesiones vivas: JWT y API keys del usuario dejan de valer.
 */

uses(RefreshDatabase::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Cache::flush();
});

function lnGestor(): User
{
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::where('name', 'security.blacklist')->pluck('id'));

    return $user;
}

/** Petición como otra identidad: limpia guard y token del JWT (singletons dentro de un test). */
function lnComo($test, array $cabeceras)
{
    return $test->asHeaders($cabeceras);
}

function lnJwt(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('bloquear a un usuario por el endpoint invalida su JWT y revoca sus API keys', function () {
    $victima = User::factory()->create();
    $jwt = lnJwt($victima);
    $key = app(ApiKeyService::class)->create($victima, 'integracion', ['profile.read'])['key'];

    lnComo($this, $jwt)->getJson('/user/profile')->assertOk();
    lnComo($this, ['X-API-Key' => $key])->getJson('/api-key/whoami')->assertOk();

    lnComo($this, lnJwt(lnGestor()))->postJson('/admin/blacklist', [
        'user_id' => $victima->id,
        'reason' => 'Cuenta comprometida',
    ])->assertCreated();

    lnComo($this, $jwt)->getJson('/user/profile')->assertUnauthorized();
    lnComo($this, ['X-API-Key' => $key])->getJson('/api-key/whoami')->assertUnauthorized();
    expect($victima->fresh()->token_version)->toBeGreaterThan(0);
    $this->assertSoftDeleted('api_keys', ['user_id' => $victima->id]);
});

test('bloquear por correo corta tambien las sesiones de la cuenta con ese correo', function () {
    $victima = User::factory()->create(['email' => 'estafador@example.com']);
    $jwt = lnJwt($victima);

    lnComo($this, lnJwt(lnGestor()))->postJson('/admin/blacklist', [
        'email' => 'ESTAFADOR@example.com',
        'reason' => 'Fraude',
    ])->assertCreated();

    lnComo($this, $jwt)->getJson('/user/profile')->assertUnauthorized();
});

test('el guard JWT rechaza a un bloqueado aunque su token siga con la version vigente', function () {
    $usuario = User::factory()->create();
    $jwt = lnJwt($usuario);
    Blacklist::create(['user_id' => $usuario->id, 'reason' => 'x']);

    lnComo($this, $jwt)->getJson('/user/profile')->assertUnauthorized();
});

test('la API key de un bloqueado no autentica aunque siga viva', function () {
    $usuario = User::factory()->create();
    $key = app(ApiKeyService::class)->create($usuario, 'integracion', ['profile.read'])['key'];
    Blacklist::create(['user_id' => $usuario->id, 'reason' => 'x']);

    lnComo($this, ['X-API-Key' => $key])->getJson('/api-key/whoami')->assertUnauthorized();
});

test('bloquear sin el permiso security.blacklist responde 403 y no toca las sesiones', function () {
    $victima = User::factory()->create();
    $jwt = lnJwt($victima);

    lnComo($this, lnJwt(User::factory()->create()))->postJson('/admin/blacklist', [
        'user_id' => $victima->id,
        'reason' => 'x',
    ])->assertForbidden();

    lnComo($this, $jwt)->getJson('/user/profile')->assertOk();
});

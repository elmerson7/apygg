<?php

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeyService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function akUsuario(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());

    return $user;
}

function akJwt(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

/** @return array{0: string, 1: ApiKey} */
function akCrear(User $user, array $scopes, ?DateTimeInterface $expira = null): array
{
    $r = app(ApiKeyService::class)->create($user, 'integracion', $scopes, $expira);

    return [$r['key'], $r['api_key']];
}

test('una key con el scope pedido accede a la ruta de ejemplo', function () {
    $user = akUsuario();
    [$key] = akCrear($user, ['profile.read']);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.api_key.scopes', ['profile.read']);
});

test('tambien se acepta la key como Bearer', function () {
    [$key] = akCrear(akUsuario(), ['profile.read']);

    $this->getJson('/api-key/whoami', ['Authorization' => 'Bearer '.$key])->assertOk();
});

test('una key sin scopes no tiene acceso a nada', function () {
    [$key] = akCrear(akUsuario(), []);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertForbidden();
});

test('una key con otro scope recibe 403', function () {
    [$key] = akCrear(akUsuario(), ['users.read']);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertForbidden();
});

test('el scope comodin da acceso explicito total', function () {
    [$key] = akCrear(akUsuario(), ['*']);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertOk();
});

test('sin key o con una key inventada responde 401', function () {
    $this->getJson('/api-key/whoami')->assertUnauthorized();
    $this->getJson('/api-key/whoami', ['X-API-Key' => 'apygg_live_inventada'])->assertUnauthorized();
});

test('un JWT no sirve como API Key', function () {
    $this->getJson('/api-key/whoami', akJwt(akUsuario()))->assertUnauthorized();
});

test('una key caducada responde 401', function () {
    [$key, $modelo] = akCrear(akUsuario(), ['profile.read']);
    $modelo->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertUnauthorized();
});

test('una key revocada deja de funcionar en el acto', function () {
    $user = akUsuario();
    [$key, $modelo] = akCrear($user, ['profile.read']);

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertOk();
    $this->withHeaders(akJwt($user))->deleteJson("/api-keys/{$modelo->id}")->assertOk();

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertUnauthorized();
});

test('las keys se pueden crear por la API y sin scopes quedan sin acceso', function () {
    $user = akUsuario();

    $key = $this->withHeaders(akJwt($user))->postJson('/api-keys', ['name' => 'Sin scopes'])
        ->assertCreated()->json('data.key');

    $this->getJson('/api-key/whoami', ['X-API-Key' => $key])->assertForbidden();
});

test('rotar una key caducada se rechaza', function () {
    $user = akUsuario();
    [, $modelo] = akCrear($user, ['profile.read']);
    $modelo->update(['expires_at' => now()->subDay()]);

    $this->withHeaders(akJwt($user))->postJson("/api-keys/{$modelo->id}/rotate")->assertUnprocessable();
});

test('rotar no alarga la vida de una key que caducaba antes del periodo de gracia', function () {
    $user = akUsuario();
    $caduca = now()->addDays(2)->startOfSecond();
    [, $modelo] = akCrear($user, ['profile.read'], $caduca);

    $this->withHeaders(akJwt($user))->postJson("/api-keys/{$modelo->id}/rotate")->assertCreated();

    expect($modelo->fresh()->expires_at->timestamp)->toBe($caduca->timestamp);
});

test('rotar una key vigente deja la antigua viva solo durante el periodo de gracia', function () {
    $user = akUsuario();
    [$vieja, $modelo] = akCrear($user, ['profile.read']);

    $nueva = $this->withHeaders(akJwt($user))->postJson("/api-keys/{$modelo->id}/rotate")
        ->assertCreated()->json('data.key');

    $this->getJson('/api-key/whoami', ['X-API-Key' => $vieja])->assertOk();
    app('auth')->forgetGuards(); // Octane lo hace entre peticiones; aquí comparten aplicación
    $this->getJson('/api-key/whoami', ['X-API-Key' => $nueva])->assertOk();
    expect($modelo->fresh()->expires_at->between(now()->addDays(6), now()->addDays(8)))->toBeTrue();
});

test('en produccion no se crean ni se aceptan keys de prueba', function () {
    $user = akUsuario();
    $r = app(ApiKeyService::class)->create($user, 'prueba', ['profile.read'], null, 'test');

    $this->getJson('/api-key/whoami', ['X-API-Key' => $r['key']])->assertOk();

    $this->app->detectEnvironment(fn () => 'production');

    $this->getJson('/api-key/whoami', ['X-API-Key' => $r['key']])->assertUnauthorized();
    $this->withHeaders(akJwt($user))->postJson('/api-keys', ['name' => 'Prueba', 'environment' => 'test'])->assertUnprocessable();
});

test('un usuario no puede revocar la key de otro', function () {
    [, $modelo] = akCrear(akUsuario(), ['profile.read']);

    $this->withHeaders(akJwt(akUsuario()))->deleteJson("/api-keys/{$modelo->id}")->assertForbidden();
});

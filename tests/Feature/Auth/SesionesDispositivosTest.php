<?php

use App\Contracts\SessionServiceInterface;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

function abrirSesionApi(User $user, string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'): array
{
    $res = test()->withHeader('User-Agent', $ua)->postJson('/auth/login', ['login' => $user->email, 'password' => 'password']);
    $res->assertOk();

    return ['access' => $res->json('data.access_token'), 'refresh' => $res->json('data.refresh_token')];
}

function bearer(string $token)
{
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();

    return test()->withHeader('Authorization', "Bearer {$token}");
}

function sidDe(string $jwt): string
{
    return JWTAuth::setToken($jwt)->getPayload()->get('sid');
}

test('cada login crea una sesion con su dispositivo y el token lleva su id', function () {
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    $sesion = UserSession::where('user_id', $user->id)->sole();

    expect(sidDe($t['access']))->toBe($sesion->public_id)
        ->and(sidDe($t['refresh']))->toBe($sesion->public_id)
        ->and($sesion->device)->toBe('Chrome en Windows')
        ->and($sesion->revoked_at)->toBeNull();
});

test('el listado de sesiones marca la actual y no expone el refresh', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user, 'Mozilla/5.0 (iPhone) Safari/604.1');
    $b = abrirSesionApi($user);

    $res = bearer($b['access'])->getJson('/user/sessions')->assertOk();

    $filas = collect($res->json('data'));
    expect($filas)->toHaveCount(2)
        ->and($filas->where('current', true)->pluck('id')->all())->toBe([sidDe($b['access'])])
        ->and($filas->where('current', false)->pluck('id')->all())->toBe([sidDe($a['access'])]);
    expect(json_encode($res->json()))->not->toContain('refresh_jti');
});

test('revocar otra sesion invalida su access y su refresh, y deja viva la actual', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);
    $b = abrirSesionApi($user);

    bearer($b['access'])->deleteJson('/user/sessions/'.sidDe($a['access']))->assertOk();

    bearer($a['access'])->getJson('/auth/me')->assertStatus(401);
    $this->postJson('/auth/refresh', ['refresh_token' => $a['refresh']])->assertStatus(401);
    bearer($b['access'])->getJson('/auth/me')->assertOk();
});

test('revocar dos veces la misma sesion responde 404 la segunda', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);
    $b = abrirSesionApi($user);
    $sid = sidDe($a['access']);

    bearer($b['access'])->deleteJson("/user/sessions/{$sid}")->assertOk();
    bearer($b['access'])->deleteJson("/user/sessions/{$sid}")->assertNotFound();
});

test('revocar una sesion con id inexistente responde 404', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);

    bearer($a['access'])->deleteJson('/user/sessions/'.fake()->uuid())->assertNotFound();
});

test('revocar todas deja solo la sesion actual', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);
    $b = abrirSesionApi($user);
    $c = abrirSesionApi($user);

    bearer($c['access'])->deleteJson('/user/sessions')->assertOk()->assertJsonPath('data.revoked', 2);

    bearer($a['access'])->getJson('/auth/me')->assertStatus(401);
    bearer($b['access'])->getJson('/auth/me')->assertStatus(401);
    bearer($c['access'])->getJson('/auth/me')->assertOk();
    expect(UserSession::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
});

test('el logout cierra la sesion', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);

    bearer($a['access'])->postJson('/auth/logout', ['refresh_token' => $a['refresh']])->assertOk();

    expect(UserSession::where('user_id', $user->id)->sole()->revoked_reason)->toBe('logout');
    $this->postJson('/auth/refresh', ['refresh_token' => $a['refresh']])->assertStatus(401);
});

test('sin token no se listan ni se revocan sesiones', function () {
    $this->getJson('/user/sessions')->assertStatus(401);
    $this->deleteJson('/user/sessions')->assertStatus(401);
});

test('cambiar o resetear la contrasena sigue invalidando todas las sesiones', function () {
    $user = User::factory()->create();
    $a = abrirSesionApi($user);
    $b = abrirSesionApi($user);

    $user->refresh()->revokeAllTokens();

    bearer($a['access'])->getJson('/auth/me')->assertStatus(401);
    bearer($b['access'])->getJson('/auth/me')->assertStatus(401);
    expect(UserSession::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

test('un refresh valido rota el token y el anterior dentro de la gracia da 401 refresh_superseded sin revocar', function () {
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    $nuevo = $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertOk();
    expect(sidDe($nuevo->json('data.access_token')))->toBe(sidDe($t['access']));
    bearer($nuevo->json('data.access_token'))->getJson('/auth/me')->assertOk();

    // Carrera legítima: el viejo se presenta segundos después -> 401 estable, la sesión sigue viva.
    $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])
        ->assertStatus(401)->assertJsonPath('code', 'refresh_superseded');

    bearer($nuevo->json('data.access_token'))->getJson('/auth/me')->assertOk();
    $this->postJson('/auth/refresh', ['refresh_token' => $nuevo->json('data.refresh_token')])->assertOk();
    expect(UserSession::where('user_id', $user->id)->sole()->revoked_at)->toBeNull();
});

test('reutilizar un refresh anterior fuera de la gracia revoca la sesion entera', function () {
    config(['auth.sessions.refresh_reuse_grace_seconds' => 10]);
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    $nuevo = $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertOk();

    $this->travel(11)->seconds();

    $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertStatus(401);

    bearer($nuevo->json('data.access_token'))->getJson('/auth/me')->assertStatus(401);
    $this->postJson('/auth/refresh', ['refresh_token' => $nuevo->json('data.refresh_token')])->assertStatus(401);
    expect(UserSession::where('user_id', $user->id)->sole()->revoked_reason)->toBe('refresh_reuse');
});

test('un refresh de dos rotaciones atras revoca la sesion aunque sea inmediato', function () {
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    $r1 = $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertOk();
    $this->postJson('/auth/refresh', ['refresh_token' => $r1->json('data.refresh_token')])->assertOk();

    $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertStatus(401);
    expect(UserSession::where('user_id', $user->id)->sole()->revoked_reason)->toBe('refresh_reuse');
});

test('con gracia 0 reutilizar el refresh anterior revoca al instante', function () {
    config(['auth.sessions.refresh_reuse_grace_seconds' => 0]);
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertOk();
    $this->postJson('/auth/refresh', ['refresh_token' => $t['refresh']])->assertStatus(401);

    expect(UserSession::where('user_id', $user->id)->sole()->revoked_reason)->toBe('refresh_reuse');
});

test('refrescar varias veces seguidas con el token vigente funciona', function () {
    $user = User::factory()->create();
    $refresh = abrirSesionApi($user)['refresh'];

    foreach (range(1, 3) as $_) {
        $res = $this->postJson('/auth/refresh', ['refresh_token' => $refresh])->assertOk();
        $refresh = $res->json('data.refresh_token');
    }
});

test('con require_sid un token sin sesion deja de valer', function () {
    $user = User::factory()->create();
    $legado = JWTAuth::fromUser($user);

    bearer($legado)->getJson('/auth/me')->assertOk();

    config(['auth.sessions.require_sid' => true]);

    bearer($legado)->getJson('/auth/me')->assertStatus(401);
});

test('un access de sesion revocada se rechaza aunque la sesion estuviera en cache', function () {
    $user = User::factory()->create();
    $t = abrirSesionApi($user);

    bearer($t['access'])->getJson('/auth/me')->assertOk(); // calienta la cache
    app(SessionServiceInterface::class)->revokeBySid(sidDe($t['access']));

    bearer($t['access'])->getJson('/auth/me')->assertStatus(401);
});

test('aislamiento: un usuario de otro tenant no ve ni revoca mis sesiones', function () {
    $this->bootIsolation();
    $userA = $this->isoUser($this->a);
    $userB = $this->isoUser($this->b);
    $sesionB = app(SessionServiceInterface::class)->start($userB, '10.0.0.9', 'Firefox');
    $headersA = $this->isoHeaders($userA, $this->a);

    $this->isoCall($headersA, 'GET', '/user/sessions')->assertOk()
        ->assertJsonMissing(['id' => $sesionB->public_id]);

    $this->isoCall($headersA, 'DELETE', '/user/sessions/'.$sesionB->public_id)->assertNotFound();
    $this->isoCall($headersA, 'DELETE', '/user/sessions')->assertOk()->assertJsonPath('data.revoked', 0);

    expect($sesionB->fresh()->revoked_at)->toBeNull();
});

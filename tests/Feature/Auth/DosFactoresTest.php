<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El límite adaptativo de `auth/*` (5 por minuto y IP) no es lo que se prueba aquí.
    config(['rate-limiting.limits.auth.max_attempts' => 1000]);
});

const SECRETO_2FA = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Código TOTP del secreto en la ventana actual (+offset de 30 s). Cada offset distinto es un código nuevo. */
function totp(string $secreto, int $ventanas = 0): string
{
    $g = app(Google2FA::class);

    return $g->oathTotp($secreto, $g->getTimestamp() + $ventanas);
}

function usuarioCon2fa(array $codigos = ['recup-0001-aaaa']): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'app_authentication_secret' => SECRETO_2FA,
        'app_authentication_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codigos),
    ])->save();

    return $user;
}

function loginPaso1(User $user)
{
    return test()->postJson('/auth/login', ['login' => $user->email, 'password' => 'password']);
}

function bearerDe(string $token)
{
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();

    return test()->withHeader('Authorization', "Bearer {$token}");
}

function tokenSimple(User $user): string
{
    return test()->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])->json('data.access_token');
}

test('un usuario sin 2FA hace login normal', function () {
    $user = User::factory()->create();

    loginPaso1($user)->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']])
        ->assertJsonMissingPath('data.challenge_token');
});

test('con 2FA el login devuelve un desafio y ningun token', function () {
    $res = loginPaso1(usuarioCon2fa())->assertOk();

    expect($res->json('data.two_factor_required'))->toBeTrue()
        ->and($res->json('data.challenge_token'))->toBeString()
        ->and($res->json('data.access_token'))->toBeNull()
        ->and($res->json('data.refresh_token'))->toBeNull();
});

test('el desafio no sirve como token de acceso', function () {
    $challenge = loginPaso1(usuarioCon2fa())->json('data.challenge_token');

    bearerDe($challenge)->getJson('/auth/me')->assertStatus(401);
});

test('un codigo TOTP correcto completa el login y abre sesion', function () {
    $user = usuarioCon2fa();
    $challenge = loginPaso1($user)->json('data.challenge_token');

    $res = $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => totp(SECRETO_2FA)])->assertOk();

    bearerDe($res->json('data.access_token'))->getJson('/auth/me')->assertOk();
    expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('un codigo incorrecto responde 401 two_factor_invalid y no abre sesion', function () {
    $user = usuarioCon2fa();
    $challenge = loginPaso1($user)->json('data.challenge_token');

    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => '000000'])
        ->assertStatus(401)->assertJsonPath('code', 'two_factor_invalid');

    expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('el mismo codigo TOTP no vale dos veces', function () {
    $user = usuarioCon2fa();
    $codigo = totp(SECRETO_2FA, -1);

    $c1 = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c1, 'code' => $codigo])->assertOk();

    $c2 = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c2, 'code' => $codigo])->assertStatus(401);
});

test('un desafio resuelto no se puede reutilizar', function () {
    $user = usuarioCon2fa();
    $challenge = loginPaso1($user)->json('data.challenge_token');

    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => totp(SECRETO_2FA, -1)])->assertOk();
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => totp(SECRETO_2FA, 1)])
        ->assertStatus(401)->assertJsonPath('code', 'two_factor_invalid');
});

test('un desafio desconocido o caducado responde 401', function () {
    $this->postJson('/auth/2fa/verify', ['challenge_token' => str_repeat('a', 64), 'code' => '123456'])
        ->assertStatus(401)->assertJsonPath('code', 'two_factor_invalid');

    $challenge = loginPaso1(usuarioCon2fa())->json('data.challenge_token');
    // La caducidad real la da el TTL de la caché: se simula olvidando la clave.
    Cache::forget('auth:2fa:challenge:'.hash('sha256', $challenge));
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => totp(SECRETO_2FA)])->assertStatus(401);
});

test('tras demasiados codigos malos se bloquea aunque el codigo final sea bueno', function () {
    config(['auth.two_factor.max_attempts' => 3]);
    $user = usuarioCon2fa();

    foreach (range(1, 3) as $_) {
        $challenge = loginPaso1($user)->json('data.challenge_token');
        $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => '111111'])->assertStatus(401);
    }

    $challenge = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challenge, 'code' => totp(SECRETO_2FA)])
        ->assertStatus(401)->assertJsonPath('code', 'account_locked');
    expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('un codigo de recuperacion sirve una sola vez', function () {
    $user = usuarioCon2fa(['recup-0001-aaaa', 'recup-0002-bbbb']);

    $c1 = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c1, 'code' => 'recup-0001-aaaa'])->assertOk();

    $c2 = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c2, 'code' => 'recup-0001-aaaa'])->assertStatus(401);

    $c3 = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c3, 'code' => 'recup-0002-bbbb'])->assertOk();
    expect($user->fresh()->getAppAuthenticationRecoveryCodes())->toBe([]);
});

test('con 2FA desactivado en la instalacion el login no pide desafio', function () {
    config(['auth.two_factor.enabled' => false]);

    loginPaso1(usuarioCon2fa())->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

test('el flujo de activacion: setup, confirmar y codigos de recuperacion', function () {
    $user = User::factory()->create();
    $token = tokenSimple($user);

    bearerDe($token)->getJson('/user/2fa')->assertOk()->assertJsonPath('data.enabled', false);

    $setup = bearerDe($token)->postJson('/user/2fa/setup')->assertOk();
    $secreto = $setup->json('data.secret');
    expect($setup->json('data.otpauth_url'))->toStartWith('otpauth://totp/')->toContain($secreto);
    // Hasta confirmar no hay 2FA.
    expect($user->fresh()->getAppAuthenticationSecret())->toBeNull();

    bearerDe($token)->postJson('/user/2fa/confirm', ['code' => '000000'])->assertStatus(401);
    expect($user->fresh()->getAppAuthenticationSecret())->toBeNull();

    $conf = bearerDe($token)->postJson('/user/2fa/confirm', ['code' => totp($secreto)])->assertOk();
    expect($conf->json('data.recovery_codes'))->toHaveCount(8);

    $user = $user->fresh();
    expect($user->getAppAuthenticationSecret())->toBe($secreto);
    // Cifrado en BD y códigos hasheados.
    expect(DB::table('users')->where('id', $user->id)->value('app_authentication_secret'))->not->toBe($secreto);
    expect(json_encode($user->getAppAuthenticationRecoveryCodes()))->not->toContain($conf->json('data.recovery_codes.0'));

    bearerDe($token)->getJson('/user/2fa')->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_remaining', 8);
});

test('no se puede iniciar la activacion si ya esta activo, ni confirmar sin setup', function () {
    $user = usuarioCon2fa();
    $token = JWTAuth::fromUser($user);
    bearerDe($token)->postJson('/user/2fa/setup')->assertStatus(422);

    $otro = User::factory()->create();
    bearerDe(JWTAuth::fromUser($otro))->postJson('/user/2fa/confirm', ['code' => '123456'])->assertStatus(422);
});

test('desactivar exige contrasena y codigo validos', function () {
    $user = usuarioCon2fa();
    $token = JWTAuth::fromUser($user);

    bearerDe($token)->deleteJson('/user/2fa', ['password' => 'mala', 'code' => totp(SECRETO_2FA, -1)])->assertStatus(401);
    expect($user->fresh()->getAppAuthenticationSecret())->not->toBeNull();

    bearerDe($token)->deleteJson('/user/2fa', ['password' => 'password', 'code' => '000000'])->assertStatus(401);
    expect($user->fresh()->getAppAuthenticationSecret())->not->toBeNull();

    bearerDe($token)->deleteJson('/user/2fa', ['password' => 'password', 'code' => totp(SECRETO_2FA, 1)])->assertOk();
    expect($user->fresh()->getAppAuthenticationSecret())->toBeNull();

    loginPaso1($user)->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

test('renovar los codigos de recuperacion invalida los anteriores', function () {
    $user = usuarioCon2fa(['recup-0001-aaaa']);
    $token = JWTAuth::fromUser($user);

    $res = bearerDe($token)->postJson('/user/2fa/recovery-codes', ['password' => 'password', 'code' => 'recup-0001-aaaa'])->assertOk();
    expect($res->json('data.recovery_codes'))->toHaveCount(8);

    $c = loginPaso1($user)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $c, 'code' => 'recup-0001-aaaa'])->assertStatus(401);
});

test('la gestion del 2FA exige sesion', function () {
    $this->getJson('/user/2fa')->assertStatus(401);
    $this->postJson('/user/2fa/setup')->assertStatus(401);
    $this->postJson('/user/2fa/confirm', ['code' => '123456'])->assertStatus(401);
    $this->deleteJson('/user/2fa', ['password' => 'x', 'code' => '1'])->assertStatus(401);
});

test('aislamiento: el 2FA y los desafios de un usuario no afectan a otro', function () {
    $a = usuarioCon2fa();
    $b = User::factory()->create();

    loginPaso1($b)->assertOk()->assertJsonStructure(['data' => ['access_token']]);

    $challengeA = loginPaso1($a)->json('data.challenge_token');
    // El código de A no abre el desafío de A con otro secreto: se simula que B tuviera otro secreto.
    $b->forceFill(['app_authentication_secret' => 'KVKFKRCPNZQUYMLXKVKFKRCPNZQUYMLX'])->save();
    $challengeB = loginPaso1($b)->json('data.challenge_token');
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challengeB, 'code' => totp(SECRETO_2FA, -1)])->assertStatus(401);
    $this->postJson('/auth/2fa/verify', ['challenge_token' => $challengeA, 'code' => totp(SECRETO_2FA, 1)])->assertOk();
});

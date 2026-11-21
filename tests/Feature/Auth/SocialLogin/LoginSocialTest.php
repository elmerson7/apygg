<?php

use App\Contracts\SocialIdentityResolverInterface;
use App\Exceptions\SocialLoginException;
use App\Models\Blacklist;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Social\SocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'rate-limiting.limits.auth.max_attempts' => 1000,
        'social-login.enabled' => true,
        'social-login.providers.google.client_ids' => ['cliente-google'],
    ]);
    RateLimiter::clear('social-login:fail:127.0.0.1');
});

/**
 * Proveedor falso: devuelve la identidad dada (o falla) sin red. Se registra UNA vez y luego se cambia la identidad:
 * el controller de una ruta se conserva entre peticiones del mismo test, así que no vale volver a enlazar el contenedor.
 */
function proveedorFalso(?SocialIdentity $identidad): void
{
    if (! app()->bound('test.social.fake')) {
        $fake = new class implements SocialIdentityResolverInterface
        {
            public ?SocialIdentity $identidad = null;

            public function resolve(string $provider, array $credentials): SocialIdentity
            {
                return $this->identidad ?? throw SocialLoginException::invalid();
            }
        };
        app()->instance('test.social.fake', $fake);
        app()->instance(SocialIdentityResolverInterface::class, $fake);
    }

    app('test.social.fake')->identidad = $identidad;
}

function identidadGoogle(string $sub, ?string $email, bool $verificado = true, string $nombre = 'Ana Pérez'): SocialIdentity
{
    return new SocialIdentity('google', $sub, $email, $verificado, $nombre);
}

function canjear(string $provider = 'google')
{
    return test()->postJson("/auth/social/{$provider}", ['id_token' => 'token-opaco']);
}

test('apagado por defecto responde 404 sin tocar nada', function () {
    config(['social-login.enabled' => false]);
    proveedorFalso(identidadGoogle('g-1', 'a@example.com'));

    canjear()->assertNotFound()->assertJsonPath('code', 'social_provider_unavailable');
    expect(SocialAccount::count())->toBe(0);
});

test('un proveedor desconocido o sin configurar responde 404', function () {
    proveedorFalso(identidadGoogle('g-1', 'a@example.com'));

    canjear('facebook')->assertNotFound()->assertJsonPath('code', 'social_provider_unavailable');

    config(['social-login.providers.google.client_ids' => []]);
    canjear()->assertNotFound();
});

test('exige id_token o access_token', function () {
    $this->postJson('/auth/social/google', [])->assertStatus(422);
});

test('vincula una cuenta existente cuando el proveedor verifica el email y abre sesion', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    proveedorFalso(identidadGoogle('g-1', 'ANA@example.com'));

    $r = canjear()->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

    expect($r->json('data.user.id'))->toBe($user->id)
        ->and(SocialAccount::where('user_id', $user->id)->where('provider_user_id', 'g-1')->exists())->toBeTrue()
        ->and(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('nunca toma una cuenta por un email que el proveedor no verifica', function () {
    $user = User::factory()->create(['email' => 'victima@example.com']);
    $version = $user->fresh()->token_version;
    proveedorFalso(identidadGoogle('g-evil', 'victima@example.com', verificado: false));

    canjear()->assertForbidden()->assertJsonPath('code', 'social_email_unverified');

    expect(SocialAccount::count())->toBe(0)
        ->and($user->fresh()->token_version)->toBe($version);
});

test('sin email tampoco vincula ni crea', function () {
    proveedorFalso(identidadGoogle('g-2', null));

    canjear()->assertForbidden()->assertJsonPath('code', 'social_email_unverified');
});

test('el segundo login entra por provider_user_id aunque el email del proveedor haya cambiado', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));
    canjear()->assertOk();

    // Ahora el proveedor reporta otro email, incluso sin verificar: manda la vinculación existente.
    proveedorFalso(identidadGoogle('g-1', 'otro@gmail.com', verificado: false));

    canjear()->assertOk()->assertJsonPath('data.user.id', $user->id);
    expect(SocialAccount::count())->toBe(1)->and(SocialAccount::first()->email)->toBe('otro@gmail.com');
});

test('al vincular una cuenta con correo sin verificar invalida su contrasena y sus sesiones', function () {
    // Alguien registró el email de otra persona sin demostrar que era suyo y conoce la contraseña.
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    $viejo = $this->postJson('/auth/login', ['login' => 'ana@example.com', 'password' => 'password'])->assertOk()->json('data.access_token');

    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));
    canjear()->assertOk();

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse();

    $this->postJson('/auth/login', ['login' => 'ana@example.com', 'password' => 'password'])->assertUnauthorized();

    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    $this->withHeader('Authorization', "Bearer {$viejo}")->getJson('/auth/me')->assertUnauthorized();
});

test('con 2FA activo devuelve el desafio y no emite tokens', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'app_authentication_recovery_codes' => []])->save();
    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));

    $r = canjear()->assertOk();

    expect($r->json('data.two_factor_required'))->toBeTrue()
        ->and($r->json('data.challenge_token'))->toBeString()
        ->and($r->json('data.access_token'))->toBeNull()
        ->and(DB::table('user_sessions')->count())->toBe(0);

    // El desafío se completa con el flujo normal de 2FA.
    $g = app(Google2FA::class);
    $this->postJson('/auth/2fa/verify', [
        'challenge_token' => $r->json('data.challenge_token'),
        'code' => $g->oathTotp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', $g->getTimestamp()),
    ])->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

test('un usuario en la lista negra no entra', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    Blacklist::create(['user_id' => $user->id, 'reason' => 'fraude']);
    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));

    canjear()->assertForbidden();
    expect(DB::table('user_sessions')->count())->toBe(0);
});

test('un usuario borrado no se reactiva ni se duplica por login social', function () {
    config(['social-login.auto_register' => true]);
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->delete();
    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));

    canjear()->assertForbidden()->assertJsonPath('code', 'social_account_not_found');
    expect(User::withTrashed()->where('email', 'ana@example.com')->count())->toBe(1);
});

test('sin cuenta y sin auto_register responde 403 y no crea nada', function () {
    proveedorFalso(identidadGoogle('g-9', 'nueva@example.com'));

    canjear()->assertForbidden()->assertJsonPath('code', 'social_account_not_found');
    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();
});

test('con auto_register crea el usuario verificado, con rol por defecto y sin contrasena usable', function () {
    config(['social-login.auto_register' => true]);
    proveedorFalso(identidadGoogle('g-9', 'Nueva@Example.com', nombre: 'Nueva Persona'));

    $r = canjear()->assertOk();
    $user = User::where('email', 'nueva@example.com')->firstOrFail();

    expect($r->json('data.user.id'))->toBe($user->id)
        ->and($user->name)->toBe('Nueva Persona')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->roles)->not->toBeEmpty()
        ->and(SocialAccount::where('user_id', $user->id)->count())->toBe(1);
});

test('con auto_register tampoco crea cuentas con email sin verificar', function () {
    config(['social-login.auto_register' => true]);
    proveedorFalso(identidadGoogle('g-9', 'nueva@example.com', verificado: false));

    canjear()->assertForbidden();
    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();
});

test('una cuenta con otra identidad del mismo proveedor no se pisa', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-original', 'email' => 'ana@example.com', 'email_verified' => true]);
    proveedorFalso(identidadGoogle('g-otra', 'ana@example.com'));

    canjear()->assertStatus(409)->assertJsonPath('code', 'social_account_conflict');
    expect(SocialAccount::where('user_id', $user->id)->pluck('provider_user_id')->all())->toBe(['g-original']);
});

test('cada identidad entra solo en su usuario (aislamiento entre cuentas y tenants)', function () {
    $a = User::factory()->create(['email' => 'a@example.com']);
    $b = User::factory()->create(['email' => 'b@example.com']);

    proveedorFalso(identidadGoogle('g-a', 'a@example.com'));
    expect(canjear()->assertOk()->json('data.user.id'))->toBe($a->id);

    proveedorFalso(identidadGoogle('g-b', 'b@example.com'));
    expect(canjear()->assertOk()->json('data.user.id'))->toBe($b->id);

    // g-a nunca llega a la cuenta de b, ni siquiera diciendo el email de b sin verificar.
    proveedorFalso(identidadGoogle('g-a', 'b@example.com', verificado: false));
    expect(canjear()->assertOk()->json('data.user.id'))->toBe($a->id);
});

test('credenciales inválidas responden 401 genérico y tras varios fallos la IP queda limitada', function () {
    config(['social-login.max_failures_per_ip' => 3]);
    proveedorFalso(null);

    foreach (range(1, 3) as $_) {
        canjear()->assertUnauthorized()->assertJsonPath('code', 'social_login_invalid');
    }

    canjear()->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

test('el login social normal no pierde el sistema de sesiones: el token sirve y se puede cerrar sesion', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    proveedorFalso(identidadGoogle('g-1', 'ana@example.com'));
    $token = canjear()->assertOk()->json('data.access_token');

    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/user/sessions')->assertOk()->assertJsonCount(1, 'data');
});

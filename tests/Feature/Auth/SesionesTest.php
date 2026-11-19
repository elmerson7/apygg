<?php

use App\Jobs\SendPasswordResetLinkJob;
use App\Models\Blacklist;
use App\Models\Logs\SecurityLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\PasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function iniciarSesion(User $user, string $password = 'password', string $ip = '10.0.0.1'): array
{
    $respuesta = test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/auth/login', ['login' => $user->email, 'password' => $password]);

    $respuesta->assertOk();

    return [
        'access' => $respuesta->json('data.access_token'),
        'refresh' => $respuesta->json('data.refresh_token'),
    ];
}

function conToken(string $token)
{
    // El guard es un singleton dentro del test; en producción cada petición
    // parte de cero (Octane lo reinicia).
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();

    return test()->withHeader('Authorization', "Bearer {$token}");
}

// ───────────── LOG-04: tipo de token ─────────────

test('rechaza un refresh token usado como access token', function () {
    $tokens = iniciarSesion(User::factory()->create());

    conToken($tokens['refresh'])->getJson('/auth/me')->assertStatus(401);
    conToken($tokens['access'])->getJson('/auth/me')->assertOk();
});

test('el endpoint de refresh rechaza un access token', function () {
    $tokens = iniciarSesion(User::factory()->create());

    $this->postJson('/auth/refresh', ['refresh_token' => $tokens['access']])->assertStatus(401);
});

test('el refresh con un refresh token válido emite tokens nuevos y rota el anterior', function () {
    $tokens = iniciarSesion(User::factory()->create());

    $nuevo = $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh']]);
    $nuevo->assertOk();

    conToken($nuevo->json('data.access_token'))->getJson('/auth/me')->assertOk();
    $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh']])->assertStatus(401);
});

// ───────────── LOG-05: revocación ─────────────

test('el logout invalida el access y el refresh token de la sesión', function () {
    $tokens = iniciarSesion(User::factory()->create());

    conToken($tokens['access'])
        ->postJson('/auth/logout', ['refresh_token' => $tokens['refresh']])
        ->assertOk();

    conToken($tokens['access'])->getJson('/auth/me')->assertStatus(401);
    $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh']])->assertStatus(401);
});

test('el logout no revoca el refresh token de otro usuario', function () {
    $mio = iniciarSesion(User::factory()->create());
    $ajeno = iniciarSesion(User::factory()->create());

    conToken($mio['access'])
        ->postJson('/auth/logout', ['refresh_token' => $ajeno['refresh']])
        ->assertOk();

    $this->postJson('/auth/refresh', ['refresh_token' => $ajeno['refresh']])->assertOk();
});

test('el cambio de contraseña invalida todas las sesiones del usuario', function () {
    $user = User::factory()->create();
    $a = iniciarSesion($user);
    $b = iniciarSesion($user);

    conToken($a['access'])->postJson('/auth/change-password', [
        'current_password' => 'password',
        'password' => 'NuevaClave123',
        'password_confirmation' => 'NuevaClave123',
    ])->assertOk();

    foreach ([$a, $b] as $sesion) {
        conToken($sesion['access'])->getJson('/auth/me')->assertStatus(401);
        $this->postJson('/auth/refresh', ['refresh_token' => $sesion['refresh']])->assertStatus(401);
    }

    // Con la contraseña nueva se entra y los tokens nuevos sí valen
    $nueva = iniciarSesion($user, 'NuevaClave123');
    conToken($nueva['access'])->getJson('/auth/me')->assertOk();
});

test('el reset de contraseña invalida todas las sesiones y el token es de un solo uso', function () {
    $user = User::factory()->create();
    $sesion = iniciarSesion($user);
    $token = app(PasswordService::class)->generateResetToken($user);

    $datos = [
        'email' => $user->email,
        'token' => $token,
        'password' => 'OtraClave123',
        'password_confirmation' => 'OtraClave123',
    ];

    $this->postJson('/auth/reset-password', $datos)->assertOk();

    conToken($sesion['access'])->getJson('/auth/me')->assertStatus(401);
    $this->postJson('/auth/refresh', ['refresh_token' => $sesion['refresh']])->assertStatus(401);

    // Segundo uso del mismo token: denegado
    $this->postJson('/auth/reset-password', array_merge($datos, [
        'password' => 'TerceraClave123',
        'password_confirmation' => 'TerceraClave123',
    ]))->assertStatus(401);
    expect(Hash::check('OtraClave123', $user->fresh()->password))->toBeTrue();
});

test('el reset de contraseña rechaza un token caducado', function () {
    $user = User::factory()->create();
    $token = app(PasswordService::class)->generateResetToken($user);

    DB::table('password_reset_tokens')->where('email', $user->email)
        ->update(['created_at' => now()->subMinutes(61)]);

    $this->postJson('/auth/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'OtraClave123',
        'password_confirmation' => 'OtraClave123',
    ])->assertStatus(401);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

// ───────────── LOG-11: forgot-password ─────────────

test('forgot-password responde igual exista o no el email', function () {
    Queue::fake();
    $user = User::factory()->create();

    $existe = $this->postJson('/auth/forgot-password', ['email' => $user->email]);
    $noExiste = $this->postJson('/auth/forgot-password', ['email' => 'nadie@example.com']);

    $existe->assertOk();
    $noExiste->assertOk();
    expect($noExiste->json('message'))->toBe($existe->json('message'));
    Queue::assertPushed(SendPasswordResetLinkJob::class, 2);
});

test('forgot-password rechaza un reset_url fuera de la lista blanca', function () {
    Queue::fake();
    config(['auth.password_reset_urls' => ['https://app.example.com']]);
    $user = User::factory()->create();

    foreach (['https://evil.tld/reset', 'https://app.example.com@evil.tld/reset', 'https://app.example.com.evil.tld/x', 'javascript://app.example.com'] as $url) {
        $this->postJson('/auth/forgot-password', ['email' => $user->email, 'reset_url' => $url])
            ->assertStatus(422);
    }

    Queue::assertNotPushed(SendPasswordResetLinkJob::class);
});

test('forgot-password acepta un reset_url de un origen permitido', function () {
    Queue::fake();
    config(['auth.password_reset_urls' => ['https://app.example.com']]);
    $user = User::factory()->create();

    $this->postJson('/auth/forgot-password', [
        'email' => $user->email,
        'reset_url' => 'https://app.example.com/restablecer',
    ])->assertOk();

    Queue::assertPushed(SendPasswordResetLinkJob::class);
});

test('el enlace del correo lleva siempre el token y el email, también con reset_url', function () {
    $user = User::factory()->create();

    foreach ([null, 'https://app.example.com/restablecer', 'https://app.example.com/#/restablecer'] as $base) {
        $mensaje = (new ResetPasswordNotification('tok123', $base))->toMail($user);

        expect($mensaje->actionUrl)
            ->toContain('token=tok123')
            ->toContain('email='.urlencode($user->email));
    }
});

test('el job de recuperación crea un token válido para un usuario existente y no hace nada si no existe', function () {
    Notification::fake();
    $user = User::factory()->create();

    (new SendPasswordResetLinkJob($user->email))->handle();
    (new SendPasswordResetLinkJob('nadie@example.com'))->handle();

    expect(DB::table('password_reset_tokens')->count())->toBe(1);
    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('forgot-password limita las solicitudes por email sin cambiar la respuesta', function () {
    Queue::fake();
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->postJson('/auth/forgot-password', ['email' => $user->email])->assertOk();
    }

    Queue::assertPushed(SendPasswordResetLinkJob::class, 3);
});

// ───────────── LOG-12: login ─────────────

test('el bloqueo por intentos no se esquiva rotando de IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
            ->postJson('/auth/login', ['login' => $user->email, 'password' => 'mala'])
            ->assertStatus(401);
    }

    // Credenciales correctas desde una IP nueva: sigue bloqueada la cuenta
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
        ->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])
        ->assertStatus(401)
        ->assertSee('Demasiados intentos');
});

test('el bloqueo por IP e identidad salta a los 5 fallos desde la misma IP', function () {
    // El límite genérico de 5/min por IP respondería antes con 429
    config(['rate-limiting.limits.auth.max_attempts' => 1000]);
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/auth/login', ['login' => $user->email, 'password' => 'mala']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])
        ->assertSee('Demasiados intentos');
});

test('el login con un usuario inexistente calcula un hash igual que uno real', function () {
    Hash::partialMock()->shouldReceive('make')->once()->andReturn('hash-ficticio');

    $this->postJson('/auth/login', ['login' => 'nadie@example.com', 'password' => 'Clave12345'])
        ->assertStatus(401);
});

test('el contador de fallos es atómico y normaliza la identidad', function () {
    $user = User::factory()->create();

    foreach (['A@x.com', 'a@x.com', ' A@X.com'] as $i => $login) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
            ->postJson('/auth/login', ['login' => $login, 'password' => 'mala']);
    }

    expect(RateLimiter::attempts('auth:failed_attempts:'.sha1('a@x.com')))->toEqual(3);
});

// ───────────── LOG-13: lista negra ─────────────

test('un usuario en la lista negra no puede iniciar sesión', function () {
    $user = User::factory()->create();
    Blacklist::create(['email' => strtoupper($user->email), 'reason' => 'abuso']);

    $this->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])
        ->assertStatus(403);
});

test('un usuario bloqueado después de iniciar sesión no puede renovar el token', function () {
    $user = User::factory()->create();
    $tokens = iniciarSesion($user);

    Blacklist::create(['user_id' => $user->id, 'reason' => 'abuso']);

    $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh']])->assertStatus(401);
});

test('el registro rechaza un email en la lista negra', function () {
    Blacklist::create(['email' => 'malo@example.com', 'reason' => 'abuso']);

    $this->postJson('/auth/register', [
        'name' => 'Malo',
        'email' => 'malo@example.com',
        'password' => 'Clave12345',
        'password_confirmation' => 'Clave12345',
    ])->assertStatus(422);

    expect(User::where('email', 'malo@example.com')->exists())->toBeFalse();
});

// ───────────── LOG-14: rate limit por usuario ─────────────

test('el rate limit por usuario cuenta por usuario autenticado y no por IP', function () {
    config(['rate-limiting.limits.read' => ['max_attempts' => 100, 'decay_minutes' => 1, 'by' => 'user']]);
    $user = User::factory()->create();
    $tokens = iniciarSesion($user);

    conToken($tokens['access'])->getJson('/auth/me')->assertOk();

    expect(RateLimiter::attempts("rate_limit:read:user:{$user->id}"))->toBeGreaterThan(0);
});

// ───────────── LOG-23: política de contraseñas ─────────────

test('registro, cambio y reset aplican la misma política de contraseñas', function () {
    $user = User::factory()->create();
    $tokens = iniciarSesion($user);
    $token = app(PasswordService::class)->generateResetToken($user);

    $this->postJson('/auth/register', [
        'name' => 'X', 'email' => 'x@example.com',
        'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    conToken($tokens['access'])->postJson('/auth/change-password', [
        'current_password' => 'password',
        'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->postJson('/auth/reset-password', [
        'email' => $user->email, 'token' => $token,
        'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

// ───────────── Registro de seguridad: un suceso, un registro ─────────────

test('un login deja exactamente un registro de login y un logout uno de revocación', function () {
    $user = User::factory()->create();

    $tokens = iniciarSesion($user);

    expect(SecurityLog::where('user_id', $user->id)
        ->where('event_type', SecurityLog::EVENT_LOGIN_SUCCESS)->count())->toBe(1);

    $this->withToken($tokens['access'])
        ->postJson('/auth/logout', ['refresh_token' => $tokens['refresh']])
        ->assertOk();

    expect(SecurityLog::where('user_id', $user->id)
        ->where('event_type', SecurityLog::EVENT_TOKEN_REVOKED)->count())->toBe(1);
});

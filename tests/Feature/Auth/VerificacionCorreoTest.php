<?php

use App\Contracts\EmailVerificationServiceInterface;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function sinVerificar(): User
{
    return User::factory()->create(['email_verified_at' => null]);
}

function tokenCorreo(User $user): string
{
    return app(EmailVerificationServiceInterface::class)->makeToken($user);
}

function bearerVerif(User $user)
{
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();

    return test()->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
}

test('el registro envia el correo de verificacion con un enlace valido', function () {
    Notification::fake();

    $this->postJson('/auth/register', [
        'name' => 'Nueva Persona', 'email' => 'nueva@example.com',
        'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
    ])->assertCreated();

    $user = User::where('email', 'nueva@example.com')->sole();

    Notification::assertSentTo($user, VerifyEmailNotification::class, function ($n) use ($user) {
        $url = $n->toMail($user)->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return isset($q['token']) && preg_match('/^\d+\.\d+\.[a-f0-9]{64}$/', $q['token']) === 1;
    });
});

test('con send_on_register apagado el registro no envia el correo', function () {
    config(['auth.email_verification.send_on_register' => false]);
    Notification::fake();

    $this->postJson('/auth/register', [
        'name' => 'Otra', 'email' => 'otra@example.com',
        'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
    ])->assertCreated();

    Notification::assertNotSentTo(User::where('email', 'otra@example.com')->sole(), VerifyEmailNotification::class);
});

test('un token valido verifica el correo y repetirlo es inofensivo', function () {
    $user = sinVerificar();
    $token = tokenCorreo($user);

    $this->postJson('/auth/email/verify', ['token' => $token])->assertOk();
    $primera = $user->fresh()->email_verified_at;
    expect($primera)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->postJson('/auth/email/verify', ['token' => $token])->assertOk();
    expect($user->fresh()->email_verified_at->equalTo($primera))->toBeTrue();
});

test('un token manipulado, mal formado o de otro usuario se rechaza con 422 token_invalid', function () {
    $user = sinVerificar();
    $otro = sinVerificar();
    [$id, $exp, $firma] = explode('.', tokenCorreo($user));

    foreach ([
        'basura',
        "{$id}.{$exp}.".str_repeat('0', 64),
        "{$otro->id}.{$exp}.{$firma}",
        ($id).'.'.($exp + 99999).".{$firma}",
    ] as $malo) {
        $this->postJson('/auth/email/verify', ['token' => $malo])->assertStatus(422)->assertJsonPath('code', 'token_invalid');
    }

    expect($user->fresh()->email_verified_at)->toBeNull()->and($otro->fresh()->email_verified_at)->toBeNull();
});

test('un token caducado se rechaza', function () {
    $user = sinVerificar();
    $token = tokenCorreo($user);

    $this->travel(2)->days();

    $this->postJson('/auth/email/verify', ['token' => $token])->assertStatus(422);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('si el correo cambia el token anterior deja de valer', function () {
    $user = sinVerificar();
    $token = tokenCorreo($user);
    $user->forceFill(['email' => 'cambiado@example.com'])->save();

    $this->postJson('/auth/email/verify', ['token' => $token])->assertStatus(422);
});

test('reenviar envia el correo, tiene limite por hora y exige sesion', function () {
    Notification::fake();
    config(['auth.email_verification.resend_per_hour' => 2]);
    $user = sinVerificar();

    $this->postJson('/auth/email/resend')->assertStatus(401);

    bearerVerif($user)->postJson('/auth/email/resend')->assertOk()->assertJsonPath('data.already_verified', false);
    bearerVerif($user)->postJson('/auth/email/resend')->assertOk();
    bearerVerif($user)->postJson('/auth/email/resend')->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);
});

test('reenviar a quien ya verifico no envia nada', function () {
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    bearerVerif($user)->postJson('/auth/email/resend')->assertOk()->assertJsonPath('data.already_verified', true);

    Notification::assertNothingSent();
});

test('el middleware verified deja pasar solo a quien verifico y responde 403 email_not_verified', function () {
    Route::middleware(['api', 'auth:api', 'verified'])->get('/_prueba/verificado', fn () => response()->json(['ok' => true]));

    bearerVerif(sinVerificar())->getJson('/_prueba/verificado')->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    bearerVerif(User::factory()->create(['email_verified_at' => now()]))->getJson('/_prueba/verificado')->assertOk();

    config(['auth.email_verification.enforce' => false]);
    bearerVerif(sinVerificar())->getJson('/_prueba/verificado')->assertOk();
});

test('aislamiento: verificar el token de un usuario no verifica a otro', function () {
    $a = sinVerificar();
    $b = sinVerificar();

    $this->postJson('/auth/email/verify', ['token' => tokenCorreo($a)])->assertOk();

    expect($a->fresh()->email_verified_at)->not->toBeNull()->and($b->fresh()->email_verified_at)->toBeNull();
});

test('cambiar el correo de un usuario anula la verificacion y reenvia el enlace', function () {
    Notification::fake();
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);

    bearerVerif($admin)->putJson("/users/{$user->id}", ['email' => 'nuevo-correo@example.com'])->assertOk();

    $user->refresh();
    expect($user->email)->toBe('nuevo-correo@example.com')->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('editar otros datos sin cambiar el correo no anula la verificacion ni reenvia nada', function () {
    Notification::fake();
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);

    bearerVerif($admin)->putJson("/users/{$user->id}", ['name' => 'Otro Nombre', 'email' => $user->email])->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('con send_on_email_change apagado se anula la verificacion pero no se envia correo', function () {
    config(['auth.email_verification.send_on_email_change' => false]);
    Notification::fake();
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);

    bearerVerif($admin)->putJson("/users/{$user->id}", ['email' => 'otro-nuevo@example.com'])->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
    Notification::assertNothingSent();
});

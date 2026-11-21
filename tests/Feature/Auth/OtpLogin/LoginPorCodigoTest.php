<?php

use App\Jobs\SendSmsJob;
use App\Models\Blacklist;
use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'rate-limiting.limits.auth.max_attempts' => 1000,
        'otp-login.enabled' => true,
        'otp-login.sms_enabled' => false,
    ]);
    Notification::fake();
    Bus::fake();
});

/** Pide un código y lo devuelve en claro (lo captura de la notificación en cola). */
function pedirCodigo(User $user): string
{
    test()->postJson('/auth/otp/request', ['email' => $user->email])->assertStatus(202);
    $codigo = null;

    Notification::assertSentTo($user, LoginCodeNotification::class, function ($n) use (&$codigo) {
        $codigo = (fn () => $this->code)->call($n);

        return true;
    });

    return $codigo;
}

function canjearCodigo(string $email, string $codigo)
{
    return test()->postJson('/auth/otp/verify', ['email' => $email, 'code' => $codigo]);
}

test('apagado por defecto responde 404 en pedir y en canjear', function () {
    config(['otp-login.enabled' => false]);

    $this->postJson('/auth/otp/request', ['email' => 'a@example.com'])->assertNotFound()->assertJsonPath('code', 'otp_login_unavailable');
    $this->postJson('/auth/otp/verify', ['email' => 'a@example.com', 'code' => '123456'])->assertNotFound();
});

test('el SMS esta apagado salvo que se active', function () {
    $this->postJson('/auth/otp/request', ['phone' => '+34600000001'])->assertNotFound();
});

test('camino feliz: pide un codigo, lo canjea y abre sesion', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);

    expect($codigo)->toMatch('/^\d{6}$/');

    canjearCodigo('ANA@example.com', $codigo)->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']])
        ->assertJsonPath('data.user.id', $user->id);

    expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('el codigo se guarda hasheado y el destino como HMAC, nunca en claro', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);
    $fila = LoginOtp::firstOrFail();

    expect($fila->code_hash)->not->toContain($codigo)
        ->and(Hash::check($codigo, $fila->code_hash))->toBeTrue()
        ->and($fila->destination_hash)->not->toContain('ana')
        ->and(json_encode(DB::table('login_otps')->first()))->not->toContain('ana@example.com');
});

test('un codigo vale una sola vez', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);

    canjearCodigo($user->email, $codigo)->assertOk();
    canjearCodigo($user->email, $codigo)->assertUnauthorized()->assertJsonPath('code', 'otp_invalid');
});

test('un codigo caducado no sirve', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);

    $this->travel(11)->minutes();

    canjearCodigo($user->email, $codigo)->assertUnauthorized()->assertJsonPath('code', 'otp_invalid');
});

test('un codigo nuevo anula el anterior', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $viejo = pedirCodigo($user);
    Notification::fake();
    $nuevo = pedirCodigo($user);

    canjearCodigo($user->email, $viejo)->assertUnauthorized();
    canjearCodigo($user->email, $nuevo)->assertOk();
});

test('tras los intentos maximos el codigo queda anulado aunque luego se acierte', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);
    $malo = $codigo === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        canjearCodigo($user->email, $malo)->assertUnauthorized();
    }

    canjearCodigo($user->email, $codigo)->assertUnauthorized();
    expect(LoginOtp::firstOrFail()->consumed_at)->not->toBeNull();
});

test('fuerza bruta: los fallos bloquean el destino aunque se pidan codigos nuevos', function () {
    config(['otp-login.verify_failures_per_destination' => 4, 'otp-login.send_per_destination' => 100, 'otp-login.send_per_ip' => 100]);
    $user = User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 4) as $_) {
        $codigo = pedirCodigo($user);
        canjearCodigo($user->email, $codigo === '000000' ? '111111' : '000000')->assertUnauthorized();
        Notification::fake();
    }

    $codigo = pedirCodigo($user);
    canjearCodigo($user->email, $codigo)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

test('limita cuantos codigos se piden por destino e IP y no distingue si la cuenta existe', function () {
    config(['otp-login.send_per_destination' => 2]);
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (['ana@example.com', 'noexiste@example.com'] as $email) {
        $this->postJson('/auth/otp/request', ['email' => $email])->assertStatus(202);
        $this->postJson('/auth/otp/request', ['email' => $email])->assertStatus(202);
        $this->postJson('/auth/otp/request', ['email' => $email])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }
});

test('limite por IP al pedir codigos de destinos distintos', function () {
    config(['otp-login.send_per_ip' => 2]);

    $this->postJson('/auth/otp/request', ['email' => 'a@example.com'])->assertStatus(202);
    $this->postJson('/auth/otp/request', ['email' => 'b@example.com'])->assertStatus(202);
    $this->postJson('/auth/otp/request', ['email' => 'c@example.com'])->assertStatus(429);
});

test('pedir un codigo no revela si el email existe: misma respuesta, sin correo ni fila para un desconocido', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $existe = $this->postJson('/auth/otp/request', ['email' => 'ana@example.com'])->assertStatus(202);
    $noExiste = $this->postJson('/auth/otp/request', ['email' => 'fantasma@example.com'])->assertStatus(202);

    expect($existe->json())->toBe($noExiste->json());
    Notification::assertSentToTimes($user, LoginCodeNotification::class, 1);
    Notification::assertCount(1);
    expect(LoginOtp::count())->toBe(1);
});

test('canjear para un email inexistente da el mismo 401 generico que un codigo malo', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    pedirCodigo($user);

    $malo = canjearCodigo('ana@example.com', '000000')->assertUnauthorized();
    $fantasma = canjearCodigo('fantasma@example.com', '000000')->assertUnauthorized();

    expect($malo->json('code'))->toBe($fantasma->json('code'))->and($malo->json('message'))->toBe($fantasma->json('message'));
});

test('el codigo de un usuario no entra en la cuenta de otro (aislamiento)', function () {
    $a = User::factory()->create(['email' => 'a@example.com']);
    $b = User::factory()->create(['email' => 'b@example.com']);
    $codigoA = pedirCodigo($a);

    canjearCodigo('b@example.com', $codigoA)->assertUnauthorized();
    canjearCodigo('a@example.com', $codigoA)->assertOk()->assertJsonPath('data.user.id', $a->id);
    expect(DB::table('user_sessions')->where('user_id', $b->id)->count())->toBe(0);
});

test('con 2FA devuelve el desafio y no emite tokens', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'app_authentication_recovery_codes' => []])->save();
    $codigo = pedirCodigo($user);

    $r = canjearCodigo($user->email, $codigo)->assertOk();

    expect($r->json('data.two_factor_required'))->toBeTrue()->and($r->json('data.access_token'))->toBeNull()
        ->and(DB::table('user_sessions')->count())->toBe(0);

    $g = app(Google2FA::class);
    $this->postJson('/auth/2fa/verify', [
        'challenge_token' => $r->json('data.challenge_token'),
        'code' => $g->oathTotp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', $g->getTimestamp()),
    ])->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

test('un usuario en la lista negra no recibe codigo ni entra', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    Blacklist::create(['user_id' => $user->id, 'reason' => 'fraude']);

    $this->postJson('/auth/otp/request', ['email' => $user->email])->assertStatus(202);

    Notification::assertNothingSent();
    expect(LoginOtp::count())->toBe(0);
});

test('un usuario bloqueado tras pedir el codigo no entra al canjearlo', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);
    Blacklist::create(['user_id' => $user->id, 'reason' => 'fraude']);

    canjearCodigo($user->email, $codigo)->assertForbidden();
    expect(DB::table('user_sessions')->count())->toBe(0);
});

test('recibir el codigo por correo verifica un correo sin verificar', function () {
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    $codigo = pedirCodigo($user);

    canjearCodigo($user->email, $codigo)->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('por SMS: envia el texto en cola a un telefono que identifica a una sola persona', function () {
    config(['otp-login.sms_enabled' => true]);
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['phone' => '+34 600 000 001']);

    $this->postJson('/auth/otp/request', ['phone' => '+34600000001'])->assertStatus(202);

    Bus::assertDispatched(SendSmsJob::class);
    expect(LoginOtp::where('channel', 'sms')->count())->toBe(1);
});

test('por SMS: un telefono compartido por dos usuarios no da acceso a ninguno', function () {
    config(['otp-login.sms_enabled' => true]);
    foreach (User::factory()->count(2)->create() as $u) {
        $u->profile()->updateOrCreate([], ['phone' => '+34600000002']);
    }

    $this->postJson('/auth/otp/request', ['phone' => '+34600000002'])->assertStatus(202);

    Bus::assertNotDispatched(SendSmsJob::class);
    expect(LoginOtp::count())->toBe(0);
});

test('valida el formato de la peticion', function () {
    $this->postJson('/auth/otp/request', [])->assertStatus(422);
    $this->postJson('/auth/otp/verify', ['email' => 'a@example.com', 'code' => 'abc'])->assertStatus(422);
});

test('otp-login:clean borra los codigos usados o caducados y conserva los vigentes', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    pedirCodigo($user);
    LoginOtp::create(['user_id' => $user->id, 'channel' => 'mail', 'destination_hash' => 'x', 'code_hash' => 'y', 'expires_at' => now()->subDays(3)]);

    $this->artisan('otp-login:clean')->assertSuccessful();

    expect(LoginOtp::count())->toBe(1);
});

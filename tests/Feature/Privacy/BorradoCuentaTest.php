<?php

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\SessionServiceInterface;
use App\Contracts\TwoFactorServiceInterface;
use App\Models\AccountDeletionRequest;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\File;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\UserDevice;
use App\Support\Privacy\Contributors\SessionsContributor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActsWithHeaders;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, ActsWithHeaders::class, InteractsWithTenancy::class);

function bH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

function bDel(): AccountDeletionServiceInterface
{
    return app(AccountDeletionServiceInterface::class);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Mail::fake();
    Storage::fake('local');
    config(['queue.default' => 'sync', 'privacy.deletion.grace_days' => 14]);
    $this->user = User::factory()->create(['name' => 'Ana Prueba']);
    $this->otro = User::factory()->create(['name' => 'Beto Ajeno']);
});

test('pedir el borrado exige la contraseña y programa la gracia sin borrar nada todavía', function () {
    $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'mala'])
        ->assertStatus(422)->assertJsonPath('code', 'deletion_password_invalid');
    expect(AccountDeletionRequest::query()->count())->toBe(0);

    $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password'])
        ->assertStatus(202)->assertJsonPath('data.status', 'pending');

    $row = AccountDeletionRequest::query()->firstOrFail();
    expect($row->scheduled_for->isFuture())->toBeTrue()
        ->and($this->user->refresh()->name)->toBe('Ana Prueba')
        ->and(AuditLog::query()->where('event', 'privacy.erasure-requested')->count())->toBe(1);
});

test('pedirlo dos veces es idempotente', function () {
    $a = $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password'])->json('data.id');
    $b = $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password'])->json('data.id');

    expect($a)->toBe($b)->and(AccountDeletionRequest::query()->count())->toBe(1);
});

test('antes de vencer la gracia el proceso no hace nada y se puede cancelar', function () {
    bDel()->request($this->user, 'password', null, '1.1.1.1');
    $row = AccountDeletionRequest::query()->firstOrFail();

    Artisan::call('privacy:process-deletions', ['--sync' => true]);
    expect($this->user->refresh()->name)->toBe('Ana Prueba');

    $this->asHeaders(bH($this->user))->deleteJson('/user/account-deletion')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->asHeaders(bH($this->user))->deleteJson('/user/account-deletion')->assertStatus(404)->assertJsonPath('code', 'deletion_not_pending');

    $this->travel(30)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);
    expect($this->user->refresh()->name)->toBe('Ana Prueba')->and($row->refresh()->status)->toBe('cancelled');
});

test('vencida la gracia se anonimiza la cuenta, se borra lo personal y se conserva la auditoría sin actor', function () {
    $email = $this->user->email;
    UserDevice::query()->create(['user_id' => $this->user->id, 'token' => 'tok-borrar-123456', 'platform' => 'ios']);
    ApiKey::query()->forceCreate(['user_id' => $this->user->id, 'name' => 'k', 'key' => hash('sha256', 'x'), 'scopes' => []]);
    Storage::disk('local')->put('uploads/a.txt', 'hola');
    $fileId = DB::table('files')->insertGetId(['user_id' => $this->user->id, 'name' => 'a', 'filename' => 'a.txt', 'path' => 'uploads/a.txt', 'disk' => 'local', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 4, 'type' => 'document', 'created_at' => now(), 'updated_at' => now()]);
    UserConsent::query()->create(['user_id' => $this->user->id, 'document_key' => 'terms', 'version' => '1', 'action' => 'accepted', 'ip_address' => '9.9.9.9', 'user_agent' => 'UA']);
    DB::table('audit_logs')->insert(['event' => 'x.hecho', 'actor_type' => 'user', 'actor_id' => (string) $this->user->id, 'ip_address' => '9.9.9.9', 'created_at' => now()]);
    Storage::disk('local')->put('uploads/pend.bin', 'x');
    DB::table('file_upload_intents')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'name' => 'p', 'filename' => 'p.bin', 'path' => 'uploads/pend.bin', 'disk' => 'local', 'mime_type' => 'application/octet-stream', 'extension' => 'bin', 'size' => 1, 'status' => 'pending', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    $tokenVersion = (int) $this->user->fresh()->token_version;

    bDel()->request($this->user, 'password', null, '1.1.1.1');
    DB::table('users')->where('id', $this->user->id)->update(['app_authentication_secret' => 'SECRETO2FA']);
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    $u = User::withTrashed()->findOrFail($this->user->id);
    expect($u->trashed())->toBeTrue()
        ->and($u->email)->not->toBe($email)->and($u->email)->toContain('@deleted.invalid')
        ->and($u->name)->toBe('Usuario eliminado')
        ->and($u->app_authentication_secret)->toBeNull()
        ->and($u->token_version)->toBeGreaterThan($tokenVersion)
        ->and(Hash::check('password', $u->password))->toBeFalse()
        ->and(UserDevice::query()->where('user_id', $u->id)->count())->toBe(0)
        ->and(DB::table('api_keys')->where('user_id', $u->id)->count())->toBe(0)
        ->and(DB::table('files')->where('id', $fileId)->exists())->toBeFalse()
        ->and(DB::table('user_consents')->where('user_id', $u->id)->first()->ip_address)->toBeNull()
        ->and(DB::table('user_consents')->where('user_id', $u->id)->first()->version)->toBe('1')
        ->and(AccountDeletionRequest::query()->firstOrFail()->status)->toBe('completed');

    Storage::disk('local')->assertMissing('uploads/a.txt');
    Storage::disk('local')->assertMissing('uploads/pend.bin');
    expect(DB::table('file_upload_intents')->where('user_id', $u->id)->count())->toBe(0);
    $audit = AuditLog::query()->where('event', 'x.hecho')->firstOrFail();
    expect($audit->actor_id)->toBeNull()->and($audit->ip_address)->toBeNull();
    // El usuario ajeno no se toca.
    expect($this->otro->refresh()->name)->toBe('Beto Ajeno');
});

test('con los archivos en modo detach se conservan sin autor', function () {
    config(['privacy.erase.files' => 'detach']);
    $fileId = DB::table('files')->insertGetId(['user_id' => $this->user->id, 'name' => 'a', 'filename' => 'a.txt', 'path' => 'uploads/a.txt', 'disk' => 'local', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 4, 'type' => 'document', 'created_at' => now(), 'updated_at' => now()]);

    bDel()->request($this->user, 'password', null, null);
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    expect(DB::table('files')->where('id', $fileId)->value('user_id'))->toBeNull();
});

test('ejecutar dos veces la misma solicitud es idempotente', function () {
    bDel()->request($this->user, 'password', null, null);
    $id = AccountDeletionRequest::query()->firstOrFail()->id;
    $this->travel(15)->days();

    bDel()->execute($id);
    bDel()->execute($id);

    expect(AuditLog::query()->where('event', 'privacy.erasure-completed')->count())->toBe(1)
        ->and(User::withTrashed()->find($this->user->id)->trashed())->toBeTrue();
});

test('el único dueño de una empresa no puede pedir el borrado y el error lo explica', function () {
    $this->enableTenancy();
    $t = $this->makeTenant('solo');
    $t->users()->attach($this->user->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);

    $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('code', 'last_owner');
    expect(AccountDeletionRequest::query()->count())->toBe(0);
});

test('con otro dueño activo sí puede borrarse y sale de la empresa', function () {
    $this->enableTenancy();
    $t = $this->makeTenant('dos');
    $t->users()->attach($this->user->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    $t->users()->attach($this->otro->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);

    bDel()->request($this->user, 'password', null, null);
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    expect(DB::table('tenant_user')->where('tenant_id', $t->id)->pluck('user_id')->all())->toBe([$this->otro->id]);
});

test('si tras pedirlo se queda de único dueño, la ejecución se bloquea sin borrar nada', function () {
    $this->enableTenancy();
    $t = $this->makeTenant('carrera');
    $t->users()->attach($this->user->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    $t->users()->attach($this->otro->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);

    bDel()->request($this->user, 'password', null, null);
    DB::table('tenant_user')->where('user_id', $this->otro->id)->delete(); // el otro dueño se va durante la gracia
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    $row = AccountDeletionRequest::query()->firstOrFail();
    expect($row->status)->toBe('failed')->and($row->failure_reason)->toBe('last_owner')
        ->and($this->user->refresh()->name)->toBe('Ana Prueba')->and($this->user->trashed())->toBeFalse();
});

test('el último administrador no puede borrarse', function () {
    $admin = User::factory()->admin()->create();

    $this->asHeaders(bH($admin))->postJson('/user/account-deletion', ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('code', 'last_admin');
});

test('sin autenticar y entre usuarios: cada uno solo ve y cancela lo suyo', function () {
    $this->asHeaders([])->postJson('/user/account-deletion', ['password' => 'password'])->assertUnauthorized();

    bDel()->request($this->user, 'password', null, null);
    $this->asHeaders(bH($this->otro))->getJson('/user/account-deletion')->assertOk()->assertJsonPath('data', null);
    $this->asHeaders(bH($this->otro))->deleteJson('/user/account-deletion')->assertStatus(404);
    expect(AccountDeletionRequest::query()->firstOrFail()->status)->toBe('pending');
});

test('privacy:purge borra solicitudes terminadas antiguas', function () {
    bDel()->request($this->user, 'password', null, null);
    bDel()->cancel($this->user);

    $this->travel(400)->days();
    Artisan::call('privacy:purge');

    expect(AccountDeletionRequest::query()->count())->toBe(0);
});

test('los datos de otros usuarios no se tocan al borrar una cuenta', function () {
    InboxNotification::query()->getModel();
    DB::table('user_devices')->insert(['user_id' => $this->otro->id, 'token' => 'tok-ajeno-123456', 'platform' => 'ios', 'created_at' => now(), 'updated_at' => now()]);
    File::query()->getModel();

    bDel()->request($this->user, 'password', null, null);
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    expect(DB::table('user_devices')->where('user_id', $this->otro->id)->count())->toBe(1);
});

test('con 2FA activo el borrado exige un código válido', function () {
    $twoFactor = app(TwoFactorServiceInterface::class);
    $secret = $twoFactor->beginSetup($this->user)['secret'];
    $google = new Google2FA;
    $twoFactor->confirmSetup($this->user, $google->getCurrentOtp($secret));

    $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('code', 'deletion_second_factor_invalid');
    $this->asHeaders(bH($this->user))->postJson('/user/account-deletion', ['password' => 'password', 'second_factor' => '000000'])
        ->assertStatus(422)->assertJsonPath('code', 'deletion_second_factor_invalid');
    expect(AccountDeletionRequest::query()->count())->toBe(0);
});

test('al borrar la cuenta se revocan y borran sus sesiones y el export no lleva el refresh', function () {
    $session = app(SessionServiceInterface::class)->start($this->user, '1.1.1.1', 'UA');
    $otra = app(SessionServiceInterface::class)->start($this->otro, '2.2.2.2', 'UA2');
    $export = (new SessionsContributor(app(SessionServiceInterface::class)))->export($this->user);
    expect(json_encode($export))->not->toContain((string) $session->refresh_jti)->and($export['sessions'])->toHaveCount(1);

    bDel()->request($this->user, 'password', null, null);
    $this->travel(15)->days();
    Artisan::call('privacy:process-deletions', ['--sync' => true]);

    expect(DB::table('user_sessions')->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(app(SessionServiceInterface::class)->isActive($session->public_id))->toBeFalse()
        ->and(DB::table('user_sessions')->where('user_id', $this->otro->id)->count())->toBe(1);
});

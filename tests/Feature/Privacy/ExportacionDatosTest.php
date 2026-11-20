<?php

use App\Contracts\PersonalDataExportServiceInterface;
use App\Jobs\GenerateDataExportJob;
use App\Models\ApiKey;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Privacy\SecretStripper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;

uses(RefreshDatabase::class, ActsWithHeaders::class);

function eH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

/** @return array<string, array<string, mixed>> contenido del ZIP por nombre de archivo */
function eZip(PersonalDataExport $export): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, Storage::disk($export->disk)->get($export->path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = json_decode($zip->getFromIndex($i), true);
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

beforeEach(function () {
    Storage::fake('local');
    config(['privacy.export.disk' => 'local', 'queue.default' => 'sync']);
    Mail::fake();
    $this->user = User::factory()->create(['name' => 'Ana Prueba']);
    $this->otro = User::factory()->create(['name' => 'Beto Ajeno']);
});

test('pedir la exportación genera un ZIP con los datos del usuario y sin secretos', function () {
    UserDevice::query()->create(['user_id' => $this->user->id, 'token' => 'TOKEN-SECRETO-1234567', 'platform' => 'android', 'name' => 'Móvil']);
    ApiKey::query()->forceCreate(['user_id' => $this->user->id, 'name' => 'mi-clave', 'key' => hash('sha256', 'CLAVE-SECRETA'), 'scopes' => ['files:read']]);
    $this->otro->forceFill(['name' => 'Beto Ajeno'])->save();

    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202)->assertJsonPath('data.status', 'pending');

    $export = PersonalDataExport::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($export->status)->toBe('ready');

    $zip = eZip($export);
    $raw = json_encode($zip);

    expect(array_keys($zip))->toContain('manifest.json', 'profile.json', 'api_keys.json', 'notifications.json', 'consents.json', 'audit.json', 'sessions.json')
        ->and($zip['profile.json']['account']['email'])->toBe($this->user->email)
        ->and($zip['api_keys.json']['api_keys'][0]['name'])->toBe('mi-clave')
        ->and($zip['notifications.json']['devices'][0]['name'])->toBe('Móvil')
        ->and($raw)->not->toContain('TOKEN-SECRETO')
        ->and($raw)->not->toContain(hash('sha256', 'CLAVE-SECRETA'))
        ->and($raw)->not->toContain($this->user->password)
        ->and($raw)->not->toContain('Beto Ajeno')
        ->and($raw)->not->toContain($this->otro->email);
});

test('un contribuidor que se despista con un secreto no lo filtra al ZIP', function () {
    $limpio = SecretStripper::strip(['ok' => 1, 'password' => 'x', 'nested' => ['app_authentication_secret' => 's', 'remember_token' => 't', 'name' => 'n'], 'api_keys' => [['key' => 'h', 'name' => 'k']]]);

    expect($limpio)->toBe(['ok' => 1, 'nested' => ['name' => 'n'], 'api_keys' => [['name' => 'k']]]);
});

test('pedirla dos veces seguidas responde 429 con code estable', function () {
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202);
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(429)->assertJsonPath('code', 'data_export_rate_limited');

    expect(PersonalDataExport::query()->count())->toBe(1);
});

test('una exportación en curso se devuelve sin crear otra', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    $a = $this->asHeaders(eH($this->user))->postJson('/user/data-export')->json('data.id');
    $b = $this->asHeaders(eH($this->user))->postJson('/user/data-export')->json('data.id');

    expect($a)->toBe($b)->and(PersonalDataExport::query()->count())->toBe(1);
    Queue::assertPushed(GenerateDataExportJob::class, 1);
});

test('el job repetido sobre una exportación lista no la rehace', function () {
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202);
    $export = PersonalDataExport::query()->firstOrFail();
    $path = $export->path;

    app(PersonalDataExportServiceInterface::class)->generate($export->id);

    expect($export->refresh()->path)->toBe($path);
});

test('el enlace firmado descarga el ZIP y uno manipulado o de otro usuario falla', function () {
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202);
    $export = PersonalDataExport::query()->firstOrFail();

    $url = $this->asHeaders(eH($this->user))->getJson("/user/data-export/{$export->id}/link")->assertOk()->json('data.url');

    $this->asHeaders([])->get($url)->assertOk()->assertHeader('content-type', 'application/zip');
    $this->asHeaders([])->get(preg_replace('/user=\d+/', 'user='.$this->otro->id, $url))->assertForbidden();
    $this->asHeaders([])->get("/user/data-export/{$export->id}/download")->assertForbidden();
    // Pedir el enlace de la exportación de otra persona: 404, no se revela.
    $this->asHeaders(eH($this->otro))->getJson("/user/data-export/{$export->id}/link")->assertNotFound();
});

test('la exportación no aparece en el listado de otro usuario', function () {
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202);

    $this->asHeaders(eH($this->otro))->getJson('/user/data-export')->assertOk()->assertJsonCount(0, 'data');
    $this->asHeaders(eH($this->user))->getJson('/user/data-export')->assertOk()->assertJsonCount(1, 'data');
});

test('una exportación caducada se borra del disco y la descarga responde 410', function () {
    $this->asHeaders(eH($this->user))->postJson('/user/data-export')->assertStatus(202);
    $export = PersonalDataExport::query()->firstOrFail();
    $path = $export->path;
    $url = $this->asHeaders(eH($this->user))->getJson("/user/data-export/{$export->id}/link")->json('data.url');

    $this->travel(100)->hours();
    expect(app(PersonalDataExportServiceInterface::class)->expireDue())->toBe(1);

    expect($export->refresh()->status)->toBe('expired');
    Storage::disk('local')->assertMissing($path);
    $this->asHeaders([])->get($url)->assertForbidden(); // la firma también caducó
    $this->asHeaders(eH($this->user))->getJson("/user/data-export/{$export->id}/link")->assertStatus(410)->assertJsonPath('code', 'data_export_expired');
});

test('sin autenticar no se puede pedir ni listar', function () {
    $this->asHeaders([])->postJson('/user/data-export')->assertUnauthorized();
    $this->asHeaders([])->getJson('/user/data-export')->assertUnauthorized();
});

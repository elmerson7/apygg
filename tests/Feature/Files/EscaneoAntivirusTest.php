<?php

use App\Contracts\FileScanServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\SettingsRegistryInterface;
use App\Enums\FileScanStatus;
use App\Events\FileInfected;
use App\Jobs\ScanFileJob;
use App\Models\File;
use App\Models\Role;
use App\Models\User;
use App\Support\Files\ScanVerdict;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\FakeMalwareScanner;
use Tests\Support\InteractsWithTenancy;

/*
 * Escaneo antivirus de las subidas (docs/files.md, "Escaneo antivirus"): apagado no cambia nada; encendido, el archivo
 * nace en cuarentena (sin URL ni descarga), el job lo escanea y queda limpio, infectado (se borra el contenido) o
 * fallido (sigue en cuarentena). Los motores reales se prueban aparte (ClamAvScannerTest, VirusTotalHashScannerTest).
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');
    Storage::fake('local');
    app(SettingsRegistryInterface::class)->setPlatform('security.allow_public_sharing', true);

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::where('name', 'user')->first());
});

/** Enciende el escaneo con motores falsos; devuelve los motores para fijar su veredicto. */
function encenderAntivirus(ScanVerdict ...$veredictos): array
{
    $motores = [];
    $engines = [];

    foreach ($veredictos ?: [ScanVerdict::clean()] as $i => $veredicto) {
        $motor = new FakeMalwareScanner($veredicto, "fake{$i}");
        $clase = 'Tests\\Support\\MotorFalso'.$i;
        app()->instance($clase, $motor);
        $engines["fake{$i}"] = $clase;
        $motores[] = $motor;
    }

    config([
        'files.scanning.enabled' => true,
        'files.scanning.engines' => $engines,
        'files.scanning.drivers' => array_keys($engines),
    ]);

    return $motores;
}

function subirParaEscanear($test, string $contenido = 'hola mundo', bool $publico = false)
{
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, $contenido);

    return $test->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($test->user)])
        ->postJson('/files', ['file' => new UploadedFile($ruta, 'nota.txt', null, null, true), 'is_public' => $publico]);
}

function escanear(File $file): FileScanStatus
{
    return app(FileScanServiceInterface::class)->scan($file->fresh());
}

test('con el escaneo apagado el archivo nace skipped, no se encola nada y se descarga como siempre', function () {
    Queue::fake();

    $id = subirParaEscanear($this)->assertCreated()->assertJsonPath('data.scan_status', 'skipped')->json('data.id');

    Queue::assertNothingPushed();
    $this->withToken(JWTAuth::fromUser($this->user))->get("/files/{$id}/download")->assertOk();
});

test('con el escaneo encendido el archivo nace en cuarentena: pending, sin ruta ni URL, y se encola el job', function () {
    Queue::fake();
    encenderAntivirus();

    $res = subirParaEscanear($this, publico: true)->assertCreated()
        ->assertJsonPath('data.scan_status', 'pending')
        ->assertJsonPath('data.url', null)
        ->assertJsonMissingPath('data.path');

    Queue::assertPushed(ScanFileJob::class, fn (ScanFileJob $job) => $job->fileId === $res->json('data.id'));
});

test('en cuarentena la descarga y la URL temporal responden 409 file_scan_pending', function () {
    Queue::fake();
    encenderAntivirus();
    $id = subirParaEscanear($this)->json('data.id');
    $token = JWTAuth::fromUser($this->user);

    $this->withToken($token)->getJson("/files/{$id}/download")->assertStatus(409)->assertJsonPath('code', 'file_scan_pending');
    $this->withToken($token)->getJson("/files/{$id}/temporary-url")->assertStatus(409)->assertJsonPath('code', 'file_scan_pending');
});

test('la URL firmada tampoco sirve un archivo en cuarentena', function () {
    Queue::fake();
    encenderAntivirus();
    $id = subirParaEscanear($this)->json('data.id');

    config(['files.scanning.enabled' => false]); // la URL se firma igual; la cuarentena la decide el estado guardado
    $url = app(FileServiceInterface::class)->temporaryDownloadUrl(File::findOrFail($id));

    $this->getJson($url)->assertStatus(409)->assertJsonPath('code', 'file_scan_pending');
});

test('otro usuario recibe 403 y no se entera de la cuarentena', function () {
    Queue::fake();
    encenderAntivirus();
    // Sin petición previa: en los tests el guard JWT recordaría al usuario de la subida.
    $archivo = File::factory()->for($this->user)->create(['is_public' => false]);
    $otro = User::factory()->create();
    $otro->assignRole(Role::where('name', 'user')->first());

    expect($archivo->scan_status)->toBe(FileScanStatus::Pending);
    $this->withToken(JWTAuth::fromUser($otro))->getJson("/files/{$archivo->id}/download")->assertForbidden();
    $this->withToken(JWTAuth::fromUser($otro))->getJson("/files/{$archivo->id}/temporary-url")->assertForbidden();
});

test('limpio: pasa a clean, se descarga y el público recupera su URL', function () {
    Queue::fake();
    [$motor] = encenderAntivirus(ScanVerdict::clean());
    $id = subirParaEscanear($this, 'contenido limpio', publico: true)->json('data.id');

    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Clean)
        ->and($motor->scanned)->toBe(['contenido limpio']);

    $file = File::findOrFail($id);
    expect($file->scanned_at)->not->toBeNull()->and($file->getUrl())->not->toBeNull();

    $this->withToken(JWTAuth::fromUser($this->user))->get("/files/{$id}/download")->assertOk();
    $this->withToken(JWTAuth::fromUser($this->user))->getJson("/files/{$id}")->assertJsonPath('data.scan_status', 'clean');
});

test('infectado: borra el contenido, queda infected con la firma, avisa con FileInfected y la descarga da 410', function () {
    Queue::fake();
    Event::fake([FileInfected::class]);
    encenderAntivirus(ScanVerdict::infected('fake:Eicar-Test'));
    $id = subirParaEscanear($this, publico: true)->json('data.id');
    $file = File::findOrFail($id);

    expect(escanear($file))->toBe(FileScanStatus::Infected);

    $file->refresh();
    expect($file->scan_result)->toBe('fake:Eicar-Test')
        ->and($file->url)->toBeNull()
        ->and(Storage::disk($file->disk)->exists($file->path))->toBeFalse();

    Event::assertDispatched(FileInfected::class, fn (FileInfected $e) => $e->file->is($file));
    $this->withToken(JWTAuth::fromUser($this->user))->getJson("/files/{$id}/download")->assertStatus(410)->assertJsonPath('code', 'file_infected');
});

test('un archivo infectado deja de contar en la cuota de almacenamiento', function () {
    Queue::fake();
    encenderAntivirus(ScanVerdict::infected('fake:x'));
    $id = subirParaEscanear($this, str_repeat('a', 1000))->json('data.id');
    $servicio = app(FileServiceInterface::class);

    expect($servicio->usedStorageBytes())->toBe(1000);

    escanear(File::findOrFail($id));

    expect($servicio->usedStorageBytes())->toBe(0);
});

test('con varios motores el primero que detecta decide y los siguientes no se llaman', function () {
    Queue::fake();
    [$primero, $segundo] = encenderAntivirus(ScanVerdict::infected('fake0:x'), ScanVerdict::clean());
    $id = subirParaEscanear($this)->json('data.id');

    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Infected)
        ->and($primero->scanned)->toHaveCount(1)
        ->and($segundo->scanned)->toBe([]);
});

test('con varios motores basta uno que detecte aunque el primero lo dé por limpio', function () {
    Queue::fake();
    encenderAntivirus(ScanVerdict::clean('fake0: hash desconocido'), ScanVerdict::infected('fake1:x'));
    $id = subirParaEscanear($this)->json('data.id');

    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Infected);
});

test('un fallo pasajero lanza para que el job reintente y el archivo sigue pending', function () {
    Queue::fake();
    encenderAntivirus(ScanVerdict::error('clamd caído'));
    $id = subirParaEscanear($this)->json('data.id');

    expect(fn () => escanear(File::findOrFail($id)))->toThrow(RuntimeException::class, 'clamd caído');
    expect(File::findOrFail($id)->scan_status)->toBe(FileScanStatus::Pending);
});

test('agotados los reintentos el job deja el archivo failed, en cuarentena (409 file_scan_failed)', function () {
    Queue::fake();
    encenderAntivirus(ScanVerdict::error('clamd caído'));
    $id = subirParaEscanear($this)->json('data.id');

    (new ScanFileJob($id))->failed(new RuntimeException('clamd caído'));

    $file = File::findOrFail($id);
    expect($file->scan_status)->toBe(FileScanStatus::Failed)->and($file->scan_result)->toBe('clamd caído');
    $this->withToken(JWTAuth::fromUser($this->user))->getJson("/files/{$id}/download")->assertStatus(409)->assertJsonPath('code', 'file_scan_failed');
});

test('un fallo que no se arregla reintentando deja el archivo failed sin lanzar', function () {
    Queue::fake();
    encenderAntivirus(ScanVerdict::error('falta la clave', retryable: false));
    $id = subirParaEscanear($this)->json('data.id');

    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Failed)
        ->and(File::findOrFail($id)->scan_result)->toBe('falta la clave');
});

test('un archivo fallido se vuelve a escanear y uno ya escaneado no se toca', function () {
    Queue::fake();
    [$motor] = encenderAntivirus(ScanVerdict::error('caído', retryable: false));
    $id = subirParaEscanear($this)->json('data.id');
    escanear(File::findOrFail($id));

    $motor->verdict = ScanVerdict::clean();
    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Clean);

    $motor->verdict = ScanVerdict::infected('fake:x');
    expect(escanear(File::findOrFail($id)))->toBe(FileScanStatus::Clean)
        ->and($motor->scanned)->toHaveCount(2);
});

test('lo que supera max_size_mb no se escanea: block lo deja failed y allow lo deja clean con la nota', function () {
    Queue::fake();
    [$motor] = encenderAntivirus();
    config(['files.scanning.max_size_mb' => 0]);
    $bloqueado = subirParaEscanear($this)->json('data.id');

    expect(escanear(File::findOrFail($bloqueado)))->toBe(FileScanStatus::Failed);

    config(['files.scanning.oversize' => 'allow']);
    $permitido = subirParaEscanear($this)->json('data.id');

    expect(escanear(File::findOrFail($permitido)))->toBe(FileScanStatus::Clean)
        ->and(File::findOrFail($permitido)->scan_result)->toContain('sin escanear')
        ->and($motor->scanned)->toBe([]);
});

test('el job escanea el archivo de verdad al ejecutarse', function () {
    encenderAntivirus(ScanVerdict::clean());
    config(['queue.default' => 'sync']);

    $id = subirParaEscanear($this)->assertCreated()->json('data.id');

    expect(File::findOrFail($id)->scan_status)->toBe(FileScanStatus::Clean);
});

test('un motor desconocido en FILE_SCAN_DRIVERS falla al resolver el servicio', function () {
    config(['files.scanning.enabled' => true, 'files.scanning.drivers' => ['noexiste']]);

    expect(fn () => app(FileScanServiceInterface::class))->toThrow(InvalidArgumentException::class, 'noexiste');
});

test('sin motores configurados el escaneo cuenta como apagado', function () {
    config(['files.scanning.enabled' => true, 'files.scanning.drivers' => []]);

    expect(app(FileScanServiceInterface::class)->enabled())->toBeFalse()
        ->and(app(FileScanServiceInterface::class)->initialStatus())->toBe(FileScanStatus::Skipped);
});

test('files:scan reencola los fallidos y, con skipped, pone en cuarentena lo subido antes de encender el escaneo', function () {
    Queue::fake();
    $antiguo = subirParaEscanear($this)->json('data.id'); // escaneo apagado: skipped
    encenderAntivirus(ScanVerdict::error('caído', retryable: false));
    $fallido = subirParaEscanear($this)->json('data.id');
    escanear(File::findOrFail($fallido));
    Queue::fake(); // olvida el job de la subida

    $this->artisan('files:scan')->expectsOutputToContain('Encolados 1')->assertSuccessful();
    Queue::assertPushed(ScanFileJob::class, fn (ScanFileJob $j) => $j->fileId === $fallido);
    expect(File::findOrFail($antiguo)->scan_status)->toBe(FileScanStatus::Skipped);

    $this->artisan('files:scan', ['--status' => 'skipped'])->expectsOutputToContain('Encolados 1')->assertSuccessful();
    expect(File::findOrFail($antiguo)->scan_status)->toBe(FileScanStatus::Pending);
});

test('files:scan rechaza un estado no válido y no hace nada con el escaneo apagado', function () {
    $this->artisan('files:scan')->expectsOutputToContain('apagado')->assertSuccessful();

    encenderAntivirus();
    $this->artisan('files:scan', ['--status' => 'clean'])->assertExitCode(2);
});

test('con multiempresa files:scan encola cada archivo en su tenant y el job lo escanea ahí', function () {
    // Cola sync: el job corre dentro del tenant que fija quien lo encola; sin él, File::query() fallaría cerrado.
    config(['queue.default' => 'sync']);
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $ctx = app(TenantContext::class);
    Storage::disk('local')->put('a.txt', 'de acme');
    Storage::disk('local')->put('b.txt', 'de beta');
    [$motor] = encenderAntivirus(ScanVerdict::error('caído', retryable: false));

    $deA = $ctx->run($a, fn () => File::factory()->create(['disk' => 'local', 'path' => 'a.txt']));
    $deB = $ctx->run($b, fn () => File::factory()->create(['disk' => 'local', 'path' => 'b.txt']));
    $estado = fn (File $f) => TenantQuery::withoutTenant(fn () => File::query()->find($f->id)->scan_status);

    expect($estado($deA))->toBe(FileScanStatus::Failed)->and($estado($deB))->toBe(FileScanStatus::Failed);

    $motor->verdict = ScanVerdict::clean();
    $this->artisan('files:scan', ['--status' => 'failed'])->expectsOutputToContain('Encolados 2')->assertSuccessful();

    expect($estado($deA))->toBe(FileScanStatus::Clean)
        ->and($estado($deB))->toBe(FileScanStatus::Clean)
        ->and($motor->scanned)->toBe(['de acme', 'de beta', 'de acme', 'de beta']);
});

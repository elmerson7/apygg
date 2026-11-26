<?php

use App\Enums\FileScanStatus;
use App\Integrations\Antivirus\ClamAvScanner;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
| ClamAV REAL (contenedor del perfil `clamav`). Se omite sin INTEGRATION_CLAMAV_HOST. Para correrlo:
|   make up CLAMAV=true   (espera a que clamd cargue las firmas: ~1 min la primera vez)
|   INTEGRATION_CLAMAV_HOST=clamav make test-integration
| EICAR es el archivo de prueba estándar de los antivirus: inofensivo, pero todos lo detectan.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $host = getenv('INTEGRATION_CLAMAV_HOST');
    if (! $host) {
        $this->markTestSkipped('INTEGRATION_CLAMAV_HOST no definida: sin clamd.');
    }

    config(['files.scanning.clamav.host' => $host, 'files.scanning.clamav.port' => (int) (getenv('INTEGRATION_CLAMAV_PORT') ?: 3310)]);
});

function clamavStream(string $contenido)
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $contenido);
    rewind($stream);

    return $stream;
}

test('clamd real detecta el archivo de prueba EICAR', function () {
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    $veredicto = (new ClamAvScanner)->scan(clamavStream($eicar), strlen($eicar));

    expect($veredicto->isInfected())->toBeTrue()
        ->and($veredicto->detail)->toContain('Eicar');
});

test('clamd real da por limpio un texto normal', function () {
    $veredicto = (new ClamAvScanner)->scan(clamavStream('hola, esto es un texto normal'), 29);

    expect($veredicto->isClean())->toBeTrue();
});

test('clamd real rechaza lo que supera StreamMaxLength sin reintentos', function () {
    $limite = (int) (getenv('INTEGRATION_CLAMAV_MAX_MB') ?: 100);
    $grande = fopen('php://temp', 'r+');
    for ($i = 0; $i <= $limite; $i++) {
        fwrite($grande, str_repeat('a', 1048576));
    }
    rewind($grande);

    $veredicto = (new ClamAvScanner)->scan($grande, ($limite + 1) * 1048576);

    expect($veredicto->isError())->toBeTrue()->and($veredicto->retryable)->toBeFalse();
})->skip(fn () => ! getenv('INTEGRATION_CLAMAV_BIG'), 'Solo con INTEGRATION_CLAMAV_BIG=1 (sube >100 MB a clamd).');

test('de punta a punta: subir EICAR con el escaneo encendido lo deja infected y borra su contenido', function () {
    Storage::fake('local');
    config(['files.scanning.enabled' => true, 'files.scanning.drivers' => ['clamav'], 'queue.default' => 'sync']);
    $user = User::factory()->create();
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');

    $id = $this->withToken(JWTAuth::fromUser($user))
        ->postJson('/files', ['file' => new UploadedFile($ruta, 'eicar.txt', null, null, true)])
        ->assertCreated()->json('data.id');

    $file = File::findOrFail($id);
    expect($file->scan_status)->toBe(FileScanStatus::Infected)
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

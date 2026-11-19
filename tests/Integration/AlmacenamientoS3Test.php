<?php

use App\Contracts\FileServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Integración con el emulador S3 REAL. Se omite si INTEGRATION_S3_ENDPOINT no está
| definida (make test-integration la define). Ver docs/local-services.md.
*/

beforeEach(function () {
    $endpoint = getenv('INTEGRATION_S3_ENDPOINT');
    if (! $endpoint) {
        $this->markTestSkipped('INTEGRATION_S3_ENDPOINT no definida: sin emulador S3.');
    }

    $comun = [
        'driver' => 's3',
        'key' => getenv('INTEGRATION_S3_KEY') ?: env('AWS_ACCESS_KEY_ID', 'apygg-dev'),
        'secret' => getenv('INTEGRATION_S3_SECRET') ?: env('AWS_SECRET_ACCESS_KEY', 'apygg-dev-secret'),
        'region' => 'us-east-1',
        'endpoint' => rtrim($endpoint, '/'),
        'use_path_style_endpoint' => true,
        'throw' => false,
    ];
    config([
        'filesystems.disks.s3' => $comun + ['bucket' => getenv('INTEGRATION_S3_BUCKET') ?: 'apygg', 'url' => null],
        'filesystems.disks.s3_private' => $comun + ['bucket' => getenv('INTEGRATION_S3_PRIVATE_BUCKET') ?: 'apygg-private', 'visibility' => 'private'],
        'files.disks.public' => 's3',
        'files.disks.private' => 's3_private',
    ]);
    Storage::purge('s3');
    Storage::purge('s3_private');

    $this->archivos = app(FileServiceInterface::class);
});

function archivoDePrueba(string $contenido): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, 'nota.txt', null, null, true);
}

test('un archivo privado se sube al bucket privado, se lee por URL temporal y se borra', function () {
    $contenido = 'privado '.bin2hex(random_bytes(8));

    $info = $this->archivos->upload(archivoDePrueba($contenido), 'integracion', null, $this->archivos->diskFor(false), false);

    expect($info['disk'])->toBe('s3_private');
    expect(Storage::disk('s3_private')->exists($info['path']))->toBeTrue()
        ->and(Storage::disk('s3')->exists($info['path']))->toBeFalse();

    // Sin firma, el bucket privado no entrega el objeto.
    $sinFirma = rtrim(config('filesystems.disks.s3_private.endpoint'), '/').'/'.config('filesystems.disks.s3_private.bucket').'/'.$info['path'];
    expect(Http::get($sinFirma)->status())->toBeIn([401, 403]);

    // Con la URL temporal firmada, sí.
    $url = $this->archivos->getTemporaryUrl($info['path'], 's3_private', 2);
    expect($url)->not->toBeNull();
    $res = Http::get($url);
    expect($res->status())->toBe(200)->and($res->body())->toBe($contenido);

    expect($this->archivos->delete($info['path'], 's3_private'))->toBeTrue()
        ->and(Storage::disk('s3_private')->exists($info['path']))->toBeFalse();
});

test('un archivo publico es accesible sin firma desde el bucket publico', function () {
    $contenido = 'publico '.bin2hex(random_bytes(8));

    $info = $this->archivos->upload(archivoDePrueba($contenido), 'integracion', null, $this->archivos->diskFor(true), true);

    expect($info['disk'])->toBe('s3');
    $res = Http::get($this->archivos->getUrl($info['path'], 's3'));
    expect($res->status())->toBe(200)->and($res->body())->toBe($contenido);

    $this->archivos->delete($info['path'], 's3');
    expect(Storage::disk('s3')->exists($info['path']))->toBeFalse();
});

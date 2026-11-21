<?php

use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\MultipartStorageInterface;
use App\Exceptions\DirectUploadException;
use App\Models\User;
use App\Services\Files\S3MultipartStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Subida por partes contra el emulador S3 REAL (RustFS). Se omite sin INTEGRATION_S3_ENDPOINT
| (make test-integration la define). Ver docs/files.md y docs/local-services.md.
*/

uses(RefreshDatabase::class);

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
        'files.prefix' => 'integracion',
        'files.multipart.part_size_mb' => 5,
        'plans.enabled' => false,
    ]);
    Storage::purge('s3');
    Storage::purge('s3_private');

    app()->bind(MultipartStorageInterface::class, S3MultipartStorage::class);
    $this->uploads = app(DirectUploadServiceInterface::class);
    $this->user = User::factory()->create();
});

/** PUT del cliente a una URL prefirmada. @return string ETag */
function s3PutParte(string $url, string $contenido): string
{
    $res = Http::withBody($contenido, 'application/octet-stream')->put($url);
    expect($res->status())->toBe(200);

    return trim((string) $res->header('ETag'), '"');
}

test('una subida por partes real: URLs por parte, listado, unión y verificación del objeto', function () {
    $size = 5 * 1048576 + 1048576;
    $contenido = str_repeat('abcdefghij', (int) ($size / 10)).str_repeat('z', $size % 10);

    $r = $this->uploads->createMultipart($this->user, ['name' => 'grande.txt', 'size' => $size, 'mime_type' => 'text/plain']);
    $intent = $r['intent'];
    expect($r['parts_count'])->toBe(2)->and($r['part_size'])->toBe(5 * 1048576);

    $urls = $this->uploads->presignParts($intent, [1, 2]);
    expect($urls['parts'])->toHaveCount(2);

    s3PutParte($urls['parts'][0]['url'], substr($contenido, 0, $r['part_size']));

    // Falta la parte 2: no se puede completar y se puede reintentar.
    $listadas = $this->uploads->listParts($intent->fresh());
    expect($listadas['parts'])->toHaveCount(1)->and($listadas['parts'][0]['part_number'])->toBe(1);
    expect(fn () => $this->uploads->complete($intent->fresh()))->toThrow(DirectUploadException::class, 'Faltan partes');

    s3PutParte($urls['parts'][1]['url'], substr($contenido, $r['part_size']));
    $file = $this->uploads->complete($intent->fresh());

    expect($file->size)->toBe($size)->and(Storage::disk('s3_private')->size($file->path))->toBe($size)
        ->and(Storage::disk('s3_private')->get($file->path))->toBe($contenido);
    // Completar de nuevo: el proveedor ya no conoce la subida y se devuelve el mismo archivo.
    expect($this->uploads->complete($intent->fresh())->id)->toBe($file->id);
});

test('abortar una subida por partes real libera las partes en el bucket', function () {
    $r = $this->uploads->createMultipart($this->user, ['name' => 'a.txt', 'size' => 6 * 1048576, 'mime_type' => 'text/plain']);
    $intent = $r['intent'];
    $url = $this->uploads->presignParts($intent, [1])['parts'][0]['url'];
    s3PutParte($url, str_repeat('a', $r['part_size']));

    $storage = app(MultipartStorageInterface::class);
    $uploadId = $intent->multipart->upload_id;
    expect($storage->listParts($intent->disk, $intent->path, $uploadId))->toHaveCount(1);

    $this->uploads->abort($intent->fresh());

    expect($storage->listParts($intent->disk, $intent->path, $uploadId))->toBeNull()
        ->and(Storage::disk($intent->disk)->exists($intent->path))->toBeFalse();
    // Abortar otra vez no falla.
    $this->uploads->abort($intent->fresh());
});

test('una parte de tamaño equivocado en el bucket real descarta la subida', function () {
    $r = $this->uploads->createMultipart($this->user, ['name' => 'm.txt', 'size' => 6 * 1048576, 'mime_type' => 'text/plain']);
    $intent = $r['intent'];
    $urls = $this->uploads->presignParts($intent, [1, 2])['parts'];
    s3PutParte($urls[0]['url'], str_repeat('a', 5 * 1048576));
    s3PutParte($urls[1]['url'], str_repeat('a', 1000)); // la última debía medir 1 MiB

    expect(fn () => $this->uploads->complete($intent->fresh()))->toThrow(DirectUploadException::class, 'no coinciden');
    expect(app(MultipartStorageInterface::class)->listParts($intent->disk, $intent->path, $intent->multipart->upload_id))->toBeNull();
});

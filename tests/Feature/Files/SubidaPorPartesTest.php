<?php

use App\Contracts\MultipartStorageInterface;
use App\Contracts\PlanLimitsInterface;
use App\Models\File;
use App\Models\FileMultipartUpload;
use App\Models\FileUploadIntent;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\FakeMultipartStorage;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

const MP_PARTE = 5 * 1024 * 1024;

beforeEach(function () {
    $this->bootIsolation();
    config(['plans.enabled' => true, 'files.prefix' => 'testing', 'files.multipart.part_size_mb' => 5]);
    Storage::fake('public');
    Storage::fake('local');

    $this->s3 = new FakeMultipartStorage('local');
    app()->instance(MultipartStorageInterface::class, $this->s3);

    $this->limits = app(PlanLimitsInterface::class);
    $this->limits->setOverride($this->a->id, 'max_file_mb', 100);
    $this->limits->setOverride($this->b->id, 'max_file_mb', 100);
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $this->hA = $this->isoHeaders($this->userA, $this->a);
    $this->hB = $this->isoHeaders($this->userB, $this->b);
});

/** Crea la intención multipart (por defecto 11 MiB = 3 partes de 5, 5 y 1 MiB). */
function mpCrear($test, array $headers, array $extra = [], int $size = 2 * MP_PARTE + 1048576)
{
    return $test->isoCall($headers, 'POST', '/files/uploads/multipart', array_merge([
        'name' => 'grande.txt',
        'size' => $size,
        'mime_type' => 'text/plain',
    ], $extra));
}

/** Sube (simula el PUT) las partes indicadas con el contenido correcto. @return array{path: string, upload_id: string} */
function mpSubir($test, string $uuid, array $partes, int $size = 2 * MP_PARTE + 1048576): array
{
    [$intent, $mp] = TenantQuery::withoutTenant(function () use ($uuid) {
        $i = FileUploadIntent::query()->where('uuid', $uuid)->firstOrFail();

        return [$i, $i->multipart];
    }, 'test');

    foreach ($partes as $n) {
        $largo = $n < $mp->parts_count ? $mp->part_size : $size - ($mp->parts_count - 1) * $mp->part_size;
        $test->s3->putPart($intent->disk, $intent->path, $mp->upload_id, $n, str_repeat('a', $largo));
    }

    return ['path' => $intent->path, 'upload_id' => $mp->upload_id];
}

function mpUso($test, array $headers): int
{
    return $test->isoCall($headers, 'GET', '/files/usage')->json('data.used_bytes');
}

test('la subida por partes feliz reserva cuota, da URLs por parte, une las partes y crea el archivo', function () {
    $res = mpCrear($this, $this->hA)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.part_size', MP_PARTE)
        ->assertJsonPath('data.parts_count', 3);
    $uuid = $res->json('data.id');

    $urls = $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1, 2, 3]])->assertOk();
    expect($urls->json('data.parts'))->toHaveCount(3)
        ->and($urls->json('data.parts.0.url'))->toContain("testing/tenants/{$this->a->id}/", 'partNumber=1');

    $this->isoCall($this->hA, 'GET', "/files/uploads/{$uuid}/parts")->assertOk()->assertJsonPath('data.parts', []);
    $subida = mpSubir($this, $uuid, [1, 3]);
    $this->isoCall($this->hA, 'GET', "/files/uploads/{$uuid}/parts")->assertOk()
        ->assertJsonCount(2, 'data.parts')->assertJsonPath('data.parts.1.part_number', 3);
    mpSubir($this, $uuid, [2]);

    $done = $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk()
        ->assertJsonPath('data.mime_type', 'text/plain')
        ->assertJsonPath('data.size', 2 * MP_PARTE + 1048576);

    $file = TenantQuery::withoutTenant(fn () => File::findOrFail($done->json('data.id')), 'test');
    expect($file->path)->toBe($subida['path'])->and($file->tenant_id)->toBe($this->a->id)
        ->and(Storage::disk('local')->size($file->path))->toBe(2 * MP_PARTE + 1048576);
    expect(Storage::disk('local')->exists("{$file->path}.mp/{$subida['upload_id']}/.meta"))->toBeFalse();
});

test('completar dos veces es idempotente: mismo archivo, una sola unión y sin duplicar', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);

    $uno = $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk()->json('data.id');
    $dos = $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk()->json('data.id');

    expect($dos)->toBe($uno)->and($this->s3->completeCalls)->toBe(1)
        ->and(TenantQuery::withoutTenant(fn () => File::count(), 'test'))->toBe(1);
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1]])
        ->assertStatus(409)->assertJsonPath('code', 'upload_intent_completed');
});

test('la cuota excedida rechaza la intención multipart y la reserva cuenta como uso', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 12);

    mpCrear($this, $this->hA)->assertCreated();
    expect(mpUso($this, $this->hA))->toBe(2 * MP_PARTE + 1048576);

    mpCrear($this, $this->hA)->assertStatus(403)
        ->assertJsonPath('code', 'storage_quota_exceeded')->assertJsonPath('limit_bytes', 12 * 1048576);
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::count(), 'test'))->toBe(1);

    // La cuota de B no se toca.
    mpCrear($this, $this->hB)->assertCreated();
});

test('el máximo por archivo del plan se aplica a la subida por partes', function () {
    $this->limits->setOverride($this->a->id, 'max_file_mb', 1);

    mpCrear($this, $this->hA)->assertStatus(413)->assertJsonPath('code', 'file_too_large_for_plan');
});

test('una imagen por partes respeta su tope por tipo y no el general de la subida por partes', function () {
    $imagen = ['name' => 'foto.png', 'mime_type' => 'image/png'];

    $r = mpCrear($this, $this->hA, $imagen);
    expect($r->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500)->not->toBe(413);
    expect($r->json('detail') ?? $r->json('message'))->toContain('tamaño máximo');
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::count(), 'test'))->toBe(0);

    config(['files.multipart.max_sizes_mb.image' => 20]);
    mpCrear($this, $this->hA, $imagen)->assertCreated();
});

test('abortar libera la cuota, borra las partes y deja la intención cerrada', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 12);
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    $subida = mpSubir($this, $uuid, [1, 2]);
    expect(mpUso($this, $this->hA))->toBe(2 * MP_PARTE + 1048576);

    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$uuid}")->assertOk();

    expect(mpUso($this, $this->hA))->toBe(0)
        ->and(Storage::disk('local')->exists("{$subida['path']}.mp/{$subida['upload_id']}/.meta"))->toBeFalse();
    // Idempotente, y ya no se completa ni se piden URLs.
    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$uuid}")->assertOk();
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_intent_aborted');
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1]])->assertStatus(422)->assertJsonPath('code', 'upload_intent_aborted');
    // Cabe de nuevo.
    mpCrear($this, $this->hA)->assertCreated();
});

test('una subida completada no se puede abortar', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk();

    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$uuid}")->assertStatus(409)->assertJsonPath('code', 'upload_intent_completed');
});

test('si el proveedor falla al abortar la intención sigue pendiente y reservando', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 12);
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    $this->s3->failAbort = true;

    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$uuid}")->assertStatus(502)->assertJsonPath('code', 'upload_storage_error');
    expect(mpUso($this, $this->hA))->toBe(2 * MP_PARTE + 1048576);

    $this->s3->failAbort = false;
    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$uuid}")->assertOk();
    expect(mpUso($this, $this->hA))->toBe(0);
});

test('otro tenant no ve, completa, aborta ni pide URLs de una subida ajena (404)', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);

    $this->isoCall($this->hB, 'POST', "/files/uploads/{$uuid}/complete")->assertNotFound();
    $this->isoCall($this->hB, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1]])->assertNotFound();
    $this->isoCall($this->hB, 'GET', "/files/uploads/{$uuid}/parts")->assertNotFound();
    $this->isoCall($this->hB, 'DELETE', "/files/uploads/{$uuid}")->assertNotFound();

    expect(TenantQuery::withoutTenant(fn () => File::count(), 'test'))->toBe(0);
});

test('solo quien creó la intención, o files.manage, opera sobre ella', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);
    $otro = $this->isoHeaders($this->isoUser($this->a), $this->a);

    $this->isoCall($otro, 'POST', "/files/uploads/{$uuid}/complete")->assertForbidden();
    $this->isoCall($otro, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1]])->assertForbidden();
    $this->isoCall($otro, 'GET', "/files/uploads/{$uuid}/parts")->assertForbidden();
    $this->isoCall($otro, 'DELETE', "/files/uploads/{$uuid}")->assertForbidden();
    $this->asHeaders([])->postJson("/files/uploads/{$uuid}/complete")->assertUnauthorized();
    $this->asHeaders([])->postJson('/files/uploads/multipart', ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain'])->assertUnauthorized();

    $admin = $this->isoHeaders($this->isoUser($this->a, null, ['files.manage']), $this->a);
    $this->isoCall($admin, 'POST', "/files/uploads/{$uuid}/complete")->assertOk();
});

test('completar con partes que faltan responde upload_parts_missing y se puede reintentar', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 3]);

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_parts_missing');

    mpSubir($this, $uuid, [2]);
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk();
});

test('una parte con tamaño distinto al esperado descarta la subida', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    $subida = mpSubir($this, $uuid, [1, 3]);
    $this->s3->putPart('local', $subida['path'], $subida['upload_id'], 2, str_repeat('a', 1000));

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_part_mismatch');
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_intent_aborted');
    expect(Storage::disk('local')->exists("{$subida['path']}.mp/{$subida['upload_id']}/.meta"))->toBeFalse();
});

test('un tipo mentido se detecta por el contenido real y el objeto se borra', function () {
    config(['files.multipart.max_sizes_mb.image' => 20]);
    $uuid = mpCrear($this, $this->hA, ['name' => 'f.png', 'mime_type' => 'image/png'])->assertCreated()->json('data.id');
    $subida = mpSubir($this, $uuid, [1, 2, 3]);

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_mime_mismatch');
    expect(Storage::disk('local')->exists($subida['path']))->toBeFalse()
        ->and(mpUso($this, $this->hA))->toBe(0);
});

test('un fallo del proveedor al unir no cambia el estado y se puede reintentar', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);
    $this->s3->failComplete = true;

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(502)->assertJsonPath('code', 'upload_storage_error');
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::firstOrFail()->status, 'test'))->toBe('pending');

    $this->s3->failComplete = false;
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk();
});

test('si la unión se hizo pero no se confirmó, completar de nuevo verifica el objeto ya unido', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    $subida = mpSubir($this, $uuid, [1, 2, 3]);
    // Simula una unión previa cuyo commit en BD se perdió: el objeto existe y el proveedor ya no conoce la subida.
    $this->s3->complete('local', $subida['path'], $subida['upload_id'], [1 => 'x', 2 => 'x', 3 => 'x']);

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/complete")->assertOk();
});

test('las URLs por parte validan el rango y el máximo por petición', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [4]])->assertStatus(422)->assertJsonPath('code', 'upload_part_mismatch');
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [0]])->assertStatus(422);
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => []])->assertStatus(422);
    config(['files.multipart.max_urls_per_request' => 2]);
    $this->isoCall($this->hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1, 2, 3]])->assertStatus(422);
});

test('una intención caducada ya no da URLs ni se completa', function () {
    $uuid = mpCrear($this, $this->hA)->json('data.id');
    mpSubir($this, $uuid, [1, 2, 3]);
    $this->travel(25)->hours();
    $hA = $this->isoHeaders($this->userA, $this->a);

    $this->isoCall($hA, 'POST', "/files/uploads/{$uuid}/parts", ['part_numbers' => [1]])->assertStatus(422)->assertJsonPath('code', 'upload_intent_expired');
    $this->isoCall($hA, 'POST', "/files/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_intent_expired');
});

test('el tamaño de parte crece para no pasar de max_parts y respeta el tope de S3', function () {
    config(['files.multipart.max_parts' => 4]);
    $res = mpCrear($this, $this->hA, [], 40 * 1048576)->assertCreated();

    expect($res->json('data.parts_count'))->toBeLessThanOrEqual(4)
        ->and($res->json('data.part_size') % 1048576)->toBe(0)
        ->and($res->json('data.part_size') * $res->json('data.parts_count'))->toBeGreaterThanOrEqual(40 * 1048576);
});

test('apagada por config responde multipart_unsupported', function () {
    config(['files.multipart.enabled' => false]);
    mpCrear($this, $this->hA)->assertStatus(422)->assertJsonPath('code', 'multipart_unsupported');
});

test('con un disco que no es S3 responde multipart_unsupported y no reserva cuota', function () {
    app()->instance(MultipartStorageInterface::class, new FakeMultipartStorage('otro-disco'));
    mpCrear($this, $this->hA)->assertStatus(422)->assertJsonPath('code', 'multipart_unsupported');
    expect(mpUso($this, $this->hA))->toBe(0);
});

test('una intención simple no admite URLs por parte y la intención rechaza tipos prohibidos', function () {
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn ($path) => ['url' => 'https://bucket.test/'.$path, 'headers' => []]);
    $simple = $this->isoCall($this->hA, 'POST', '/files/uploads', ['name' => 'n.txt', 'size' => 5, 'mime_type' => 'text/plain'])->assertCreated()->json('data.id');

    $this->isoCall($this->hA, 'POST', "/files/uploads/{$simple}/parts", ['part_numbers' => [1]])->assertStatus(422)->assertJsonPath('code', 'multipart_unsupported');
    mpCrear($this, $this->hA, ['name' => 'x.html', 'mime_type' => 'text/html'])->assertStatus(422);

    // Abortar también sirve para la intención simple y libera su reserva.
    $this->isoCall($this->hA, 'DELETE', "/files/uploads/{$simple}")->assertOk();
});

test('la clave de idempotencia repite la respuesta sin crear otra intención', function () {
    $h = $this->hA + ['Idempotency-Key' => 'mp-'.bin2hex(random_bytes(4))];

    $uno = mpCrear($this, $h)->assertCreated()->json('data.id');
    $dos = mpCrear($this, $h)->json('data.id');

    expect($dos)->toBe($uno)->and(TenantQuery::withoutTenant(fn () => FileUploadIntent::count(), 'test'))->toBe(1);
});

test('files:purge-intents aborta las subidas por partes caducadas y respeta las vigentes', function () {
    $vieja = mpCrear($this, $this->hA)->json('data.id');
    $subida = mpSubir($this, $vieja, [1, 2]);
    $this->travel(25)->hours();
    $vigente = mpCrear($this, $this->isoHeaders($this->userB, $this->b))->json('data.id');

    expect(Artisan::call('files:purge-intents'))->toBe(0);

    expect(Storage::disk('local')->exists("{$subida['path']}.mp/{$subida['upload_id']}/.meta"))->toBeFalse();
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::pluck('uuid')->all(), 'test'))->toBe([$vigente])
        ->and(TenantQuery::withoutTenant(fn () => FileMultipartUpload::count(), 'test'))->toBe(1);
});

test('si el proveedor no deja abortar, la purga conserva la fila para el siguiente barrido', function () {
    $vieja = mpCrear($this, $this->hA)->json('data.id');
    $this->travel(25)->hours();
    $this->s3->failAbort = true;

    Artisan::call('files:purge-intents');
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::pluck('uuid')->all(), 'test'))->toBe([$vieja]);

    $this->s3->failAbort = false;
    Artisan::call('files:purge-intents');
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::count(), 'test'))->toBe(0);
});

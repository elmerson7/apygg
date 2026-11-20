<?php

use App\Contracts\FileServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Models\File;
use App\Models\FileUploadIntent;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    config(['plans.enabled' => true, 'files.prefix' => 'testing']);
    Storage::fake('public');
    Storage::fake('local');

    // El disco privado "local" falso firma subidas: la URL es ficticia y el "PUT" lo simula el test.
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn ($path, $expires, $options) => [
        'url' => 'https://bucket.test/'.$path.'?sig=1',
        'headers' => ['Content-Type' => $options['ContentType'] ?? ''],
    ]);

    $this->limits = app(PlanLimitsInterface::class);
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $this->hA = $this->isoHeaders($this->userA, $this->a);
    $this->hB = $this->isoHeaders($this->userB, $this->b);
});

function sdTexto(string $contenido = 'hola mundo'): string
{
    return $contenido;
}

/** Crea una intención por la API y devuelve la respuesta. */
function sdIntencion($test, array $headers, array $extra = [], string $contenido = 'hola mundo')
{
    return $test->isoCall($headers, 'POST', '/files/uploads', array_merge([
        'name' => 'nota.txt',
        'size' => strlen($contenido),
        'mime_type' => 'text/plain',
    ], $extra));
}

/** Simula el PUT del cliente al bucket, en la ruta que devolvió la URL presignada. */
function sdSubir($res, string $contenido): string
{
    $path = ltrim((string) parse_url($res->json('data.upload.url'), PHP_URL_PATH), '/');
    Storage::disk('local')->put($path, $contenido);

    return $path;
}

// ── Prefijo por entorno ─────────────────────────────────────────────────────

test('la clave de almacenamiento lleva prefijo de entorno y tenant', function () {
    $files = app(FileServiceInterface::class);

    $this->ctx->run($this->a, fn () => expect($files->tenantStoragePath('uploads'))->toBe("testing/tenants/{$this->a->id}/uploads"));

    config(['files.prefix' => '']);
    $this->ctx->run($this->a, fn () => expect($files->tenantStoragePath('uploads'))->toBe("tenants/{$this->a->id}/uploads"));

    config(['files.prefix' => '../evil/x']);
    expect($files->storagePrefix())->toBe('evilx');
});

test('un archivo guardado sin prefijo sigue descargándose tras activar el prefijo', function () {
    config(['files.prefix' => '']);
    $res = $this->isoCall($this->hA, 'POST', '/files', ['file' => new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'apygg'), fn ($r) => file_put_contents($r, 'viejo')), 'v.txt', null, null, true), 'is_public' => false])->assertCreated();
    $path = TenantQuery::withoutTenant(fn () => File::findOrFail($res->json('data.id'))->path, 'test');
    expect($path)->toStartWith('tenants/');

    config(['files.prefix' => 'testing']);
    $this->withHeaders($this->hA)->get('/files/'.$res->json('data.id').'/download')->assertOk();
});

test('una subida normal nueva se guarda bajo el prefijo', function () {
    $res = $this->isoCall($this->hA, 'POST', '/files', ['file' => new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'apygg'), fn ($r) => file_put_contents($r, 'nuevo')), 'n.txt', null, null, true), 'is_public' => false])->assertCreated();
    $path = TenantQuery::withoutTenant(fn () => File::findOrFail($res->json('data.id'))->path, 'test');
    expect($path)->toStartWith("testing/tenants/{$this->a->id}/");
});

// ── Subida directa presignada ───────────────────────────────────────────────

test('la subida presignada feliz crea la intención, verifica el objeto y crea el archivo', function () {
    $res = sdIntencion($this, $this->hA)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.upload.method', 'PUT');
    expect($res->json('data.upload.url'))->toContain("testing/tenants/{$this->a->id}/");

    $path = sdSubir($res, 'hola mundo');

    $done = $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertOk()
        ->assertJsonPath('data.mime_type', 'text/plain')
        ->assertJsonPath('data.size', 10);

    $file = TenantQuery::withoutTenant(fn () => File::findOrFail($done->json('data.id')), 'test');
    expect($file->path)->toBe($path)->and($file->is_public)->toBeFalse()->and($file->tenant_id)->toBe($this->a->id);

    // Completar de nuevo es idempotente: mismo archivo, sin duplicar.
    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertOk()->assertJsonPath('data.id', $done->json('data.id'));
    expect(TenantQuery::withoutTenant(fn () => File::count(), 'test'))->toBe(1);
});

test('completar sin haber subido el objeto responde upload_object_missing y se puede reintentar', function () {
    $res = sdIntencion($this, $this->hA)->assertCreated();

    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_object_missing');

    sdSubir($res, 'hola mundo');
    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')->assertOk();
});

test('un tamaño mentido descarta la intención y borra el objeto', function () {
    $res = sdIntencion($this, $this->hA, ['size' => 5])->assertCreated();
    $path = sdSubir($res, 'esto pesa bastante mas de cinco bytes');

    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_size_mismatch');

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(TenantQuery::withoutTenant(fn () => FileUploadIntent::first()->status, 'test'))->toBe('aborted')
        ->and(TenantQuery::withoutTenant(fn () => File::count(), 'test'))->toBe(0);

    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_intent_aborted');
});

test('un tipo mentido (HTML declarado como PNG) se rechaza por el contenido real', function () {
    $html = '<html><script>alert(1)</script></html>';
    $res = sdIntencion($this, $this->hA, ['name' => 'foto.png', 'mime_type' => 'image/png', 'size' => strlen($html)])->assertCreated();
    $path = sdSubir($res, $html);

    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_mime_mismatch');
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('un checksum que no coincide descarta la subida y uno correcto la acepta', function () {
    $mal = sdIntencion($this, $this->hA, ['checksum_sha256' => str_repeat('a', 64)])->assertCreated();
    sdSubir($mal, 'hola mundo');
    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$mal->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_checksum_mismatch');

    $bien = sdIntencion($this, $this->hA, ['checksum_sha256' => hash('sha256', 'hola mundo')])->assertCreated();
    sdSubir($bien, 'hola mundo');
    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$bien->json('data.id').'/complete')->assertOk();
});

test('la intención rechaza tipos y extensiones prohibidos o no permitidos', function () {
    sdIntencion($this, $this->hA, ['name' => 'x.svg', 'mime_type' => 'image/svg+xml'])->assertStatus(422);
    sdIntencion($this, $this->hA, ['name' => 'x.php', 'mime_type' => 'text/plain'])->assertStatus(422);
    sdIntencion($this, $this->hA, ['name' => 'x.exe', 'mime_type' => 'application/x-msdownload'])->assertStatus(422);
    sdIntencion($this, $this->hA, ['size' => 0])->assertStatus(422);
    sdIntencion($this, $this->hA, ['size' => 999999999])->assertStatus(422);
    expect(TenantQuery::withoutTenant(fn () => FileUploadIntent::count(), 'test'))->toBe(0);
});

test('una intención caducada no se puede completar', function () {
    $res = sdIntencion($this, $this->hA)->assertCreated();
    sdSubir($res, 'hola mundo');
    $this->travel(40)->minutes();
    $this->hA = $this->isoHeaders($this->userA, $this->a);

    $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'upload_intent_expired');
});

test('un disco sin soporte de subida directa responde direct_upload_unsupported', function () {
    // Disco local sin `serve` ni callback: no sabe firmar subidas.
    config([
        'filesystems.disks.sin_firma' => ['driver' => 'local', 'root' => storage_path('framework/testing/sin_firma')],
        'files.disks.private' => 'sin_firma',
    ]);

    sdIntencion($this, $this->hA)->assertStatus(422)->assertJsonPath('code', 'direct_upload_unsupported');
});

test('solo quien creó la intención, o files.manage, puede completarla', function () {
    $res = sdIntencion($this, $this->hA)->assertCreated();
    sdSubir($res, 'hola mundo');
    $otro = $this->isoUser($this->a);

    $this->isoCall($this->isoHeaders($otro, $this->a), 'POST', '/files/uploads/'.$res->json('data.id').'/complete')->assertForbidden();
    $this->asHeaders([])->postJson('/files/uploads/'.$res->json('data.id').'/complete')->assertUnauthorized();

    $admin = $this->isoUser($this->a, null, ['files.manage']);
    $this->isoCall($this->isoHeaders($admin, $this->a), 'POST', '/files/uploads/'.$res->json('data.id').'/complete')->assertOk();
});

test('el comando files:purge-intents borra las caducadas con su objeto y respeta las vigentes', function () {
    $vieja = sdIntencion($this, $this->hA)->assertCreated();
    $path = sdSubir($vieja, 'hola mundo');
    $this->travel(40)->minutes();
    $this->hB = $this->isoHeaders($this->userB, $this->b);
    $vigente = sdIntencion($this, $this->hB)->assertCreated();

    expect(Artisan::call('files:purge-intents'))->toBe(0);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
    $uuids = TenantQuery::withoutTenant(fn () => FileUploadIntent::pluck('uuid')->all(), 'test');
    expect($uuids)->toBe([$vigente->json('data.id')]);
});

// ── Cuotas por plan ─────────────────────────────────────────────────────────

test('el tamaño máximo por archivo del plan se aplica en la subida normal y en la presignada', function () {
    $this->limits->setOverride($this->a->id, 'max_file_mb', 1);
    $grande = 2 * 1048576;

    sdIntencion($this, $this->hA, ['size' => $grande])
        ->assertStatus(413)->assertJsonPath('code', 'file_too_large_for_plan');

    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, str_repeat('a', $grande));
    $this->isoCall($this->hA, 'POST', '/files', ['file' => new UploadedFile($ruta, 'g.txt', null, null, true)])
        ->assertStatus(413)->assertJsonPath('code', 'file_too_large_for_plan');

    // El tenant B (plan free: 10 MB) no hereda el override de A.
    sdIntencion($this, $this->hB, ['size' => $grande])->assertCreated();
});

test('la cuota de almacenamiento bloquea la subida normal que la supera', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 1);
    $this->ctx->run($this->a, fn () => File::factory()->create(['user_id' => $this->userA->id, 'size' => 1048576 - 5]));

    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, 'doce bytes!!');
    $this->isoCall($this->hA, 'POST', '/files', ['file' => new UploadedFile($ruta, 'n.txt', null, null, true)])
        ->assertStatus(403)
        ->assertJsonPath('code', 'storage_quota_exceeded')
        ->assertJsonPath('limit_bytes', 1048576);

    expect(TenantQuery::withoutTenant(fn () => File::count(), 'test'))->toBe(1);
});

test('las intenciones vigentes reservan cuota y al caducar o borrar el archivo se libera', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 1);
    $mitad = 524288;

    $uno = sdIntencion($this, $this->hA, ['size' => $mitad])->assertCreated();
    sdIntencion($this, $this->hA, ['size' => $mitad])->assertCreated();
    sdIntencion($this, $this->hA, ['size' => 1])->assertStatus(403)->assertJsonPath('code', 'storage_quota_exceeded');

    $this->isoCall($this->hA, 'GET', '/files/usage')->assertOk()
        ->assertJsonPath('data.used_bytes', 2 * $mitad)->assertJsonPath('data.limit_bytes', 1048576)->assertJsonPath('data.remaining_bytes', 0);

    // Al caducar, la reserva se libera.
    $this->travel(40)->minutes();
    $this->hA = $this->isoHeaders($this->userA, $this->a);
    sdIntencion($this, $this->hA, ['size' => 1])->assertCreated();
    expect($uno->json('data.id'))->not->toBeNull();
});

test('completar una subida no cambia el uso total y borrar el archivo libera cuota', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 1);
    $res = sdIntencion($this, $this->hA)->assertCreated();
    sdSubir($res, 'hola mundo');

    $uso = fn () => $this->isoCall($this->hA, 'GET', '/files/usage')->json('data.used_bytes');
    expect($uso())->toBe(10);

    $id = $this->isoCall($this->hA, 'POST', '/files/uploads/'.$res->json('data.id').'/complete')->assertOk()->json('data.id');
    expect($uso())->toBe(10);

    $this->isoCall($this->hA, 'DELETE', "/files/{$id}")->assertOk();
    expect($uso())->toBe(0);
});

test('sin planes activos no se limita nada', function () {
    config(['plans.enabled' => false]);
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 0);

    sdIntencion($this, $this->hA, ['size' => 1048576])->assertCreated();
    $this->isoCall($this->hA, 'GET', '/files/usage')->assertOk()->assertJsonPath('data.limit_bytes', null);
});

test('la cuota es por tenant: llenar la de A no afecta a B', function () {
    $this->limits->setOverride($this->a->id, 'max_storage_mb', 0);

    sdIntencion($this, $this->hA)->assertStatus(403)->assertJsonPath('code', 'storage_quota_exceeded');
    sdIntencion($this, $this->hB)->assertCreated();
});

// ── Aislamiento entre tenants ───────────────────────────────────────────────

test('sin sesión no se crea ni se completa una subida', function () {
    $this->asHeaders([])->postJson('/files/uploads', ['name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain'])->assertUnauthorized();
    $this->asHeaders([])->getJson('/files/usage')->assertUnauthorized();
    expect(Storage::disk('local') instanceof FilesystemAdapter)->toBeTrue();
});

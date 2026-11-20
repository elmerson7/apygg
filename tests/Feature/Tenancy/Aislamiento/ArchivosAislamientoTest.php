<?php

use App\Models\File;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    Storage::fake('public');
    Storage::fake('local');
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $this->fileA = $this->ctx->run($this->a, fn () => File::factory()->create(['user_id' => $this->userA->id, 'name' => 'de-a.txt']));
    $this->fileB = $this->ctx->run($this->b, fn () => File::factory()->create(['user_id' => $this->userB->id, 'name' => 'de-b.txt']));
});

test('el listado de archivos desde A no muestra los de B', function () {
    $this->assertListIsolated($this->isoHeaders($this->userA, $this->a), '/files', 'name', ['de-a.txt'], ['de-b.txt']);
});

test('ver, editar, borrar, descargar y pedir URL de un archivo de B desde A responde 404', function () {
    $id = $this->fileB->id;
    $this->assertForeignIdNotFound($this->isoHeaders($this->userA, $this->a), [
        ['GET', "/files/{$id}"],
        ['PUT', "/files/{$id}", ['description' => 'x']],
        ['DELETE', "/files/{$id}"],
        ['GET', "/files/{$id}/download"],
        ['GET', "/files/{$id}/temporary-url"],
    ]);

    expect(TenantQuery::withoutTenant(fn () => File::find($id), 'test'))->not->toBeNull();
});

test('subir un archivo desde A ignora un tenant_id de B en el cuerpo', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, 'contenido');

    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->userA, $this->a),
        '/files',
        ['file' => new UploadedFile($ruta, 'a.txt', null, null, true), 'is_public' => false],
        fn ($res) => TenantQuery::withoutTenant(fn () => File::findOrFail($res->json('data.id'))->tenant_id, 'test'),
    );
});

test('un usuario de A no entra a B declarando X-Tenant de B', function () {
    $h = $this->isoHeaders($this->userA, $this->b);

    // Contrato de ResolveTenant: 403 idéntico para tenant inexistente y sin membresía (no revela qué tenants existen).
    $sinMembresia = $this->isoCall($h, 'GET', '/files')->assertForbidden();
    $inexistente = $this->isoCall(array_replace($this->isoHeaders($this->userA, $this->a), ['X-Tenant' => '999999']), 'GET', '/files')->assertForbidden();

    expect($sinMembresia->json('code'))->toBe($inexistente->json('code'));
});

test('el modelo File solo ve las filas del tenant activo y falla cerrado sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(File::query()->pluck('name')->all())->toBe(['de-a.txt']));
    $this->ctx->run($this->b, fn () => expect(File::query()->pluck('name')->all())->toBe(['de-b.txt']));
    $this->ctx->clear();
    expect(fn () => File::query()->count())->toThrow(MissingTenantContext::class);
});

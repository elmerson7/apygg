<?php

use App\Jobs\ExportJob;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->bootIsolation();
    Storage::fake('local');
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $this->memberB = $this->isoUser($this->b);
});

function exportFilas(DataExport $e): string
{
    return Storage::disk($e->disk)->get($e->path);
}

test('exportar miembros desde A solo incluye a los miembros de A', function () {
    $headers = $this->isoHeaders($this->ownerA, $this->a);
    $id = $this->isoCall($headers, 'POST', '/tenant/exports/members', ['format' => 'csv'])->assertStatus(202)->json('data.id');

    $export = DataExport::findOrFail($id);
    $csv = exportFilas($export);

    expect($export->tenant_id)->toBe($this->a->id)->and($export->status)->toBe('completed')
        ->and($csv)->toContain($this->ownerA->email)
        ->and($csv)->not->toContain($this->ownerB->email)
        ->and($csv)->not->toContain($this->memberB->email)
        ->and($export->path)->toContain('tenants/'.$this->a->id.'/exports/');
});

test('el listado de exportaciones de A no muestra las de B y las de otro tenant dan 404', function () {
    $hA = $this->isoHeaders($this->ownerA, $this->a);
    $hB = $this->isoHeaders($this->ownerB, $this->b);
    $idA = $this->isoCall($hA, 'POST', '/tenant/exports/members', ['format' => 'csv'])->json('data.id');
    $idB = $this->isoCall($hB, 'POST', '/tenant/exports/members', ['format' => 'csv'])->json('data.id');

    $this->assertListIsolated($hA, '/tenant/exports', 'id', [$idA], [$idB]);
    $this->assertForeignIdNotFound($hA, [
        ['GET', "/tenant/exports/{$idB}"],
        ['GET', "/tenant/exports/{$idB}/download"],
    ]);
});

test('el tenant_id del cuerpo se ignora al exportar', function () {
    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->ownerA, $this->a),
        '/tenant/exports/members',
        ['format' => 'csv'],
        fn ($res) => DataExport::findOrFail($res->json('data.id'))->tenant_id,
    );
});

test('un miembro sin permiso de exportar recibe 403 y sin tenant activo se rechaza con 400', function () {
    $this->isoCall($this->isoHeaders($this->memberB, $this->b), 'POST', '/tenant/exports/members', ['format' => 'csv'])->assertForbidden();

    $this->asHeaders(['Authorization' => $this->isoHeaders($this->ownerA, $this->a)['Authorization']])
        ->postJson('/tenant/exports/members', ['format' => 'csv'])->assertStatus(400)->assertJsonPath('code', 'tenant_required');
});

test('las exportaciones de plataforma no se ven desde un tenant ni al revés', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::where('name', 'admin')->first());
    $admin->tenants()->attach($this->a->id, ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    $hPlat = ['Authorization' => $this->isoHeaders($admin, $this->a)['Authorization']];
    $hTen = $this->isoHeaders($admin, $this->a);

    $plat = $this->isoCall($hPlat, 'POST', '/admin/exports/users', ['format' => 'csv'])->assertStatus(202)->json('data.id');
    $ten = $this->isoCall($hTen, 'POST', '/tenant/exports/members', ['format' => 'csv'])->assertStatus(202)->json('data.id');

    expect(DataExport::findOrFail($plat)->tenant_id)->toBeNull()->and(DataExport::findOrFail($ten)->tenant_id)->toBe($this->a->id);
    $this->assertListIsolated($hPlat, '/admin/exports', 'id', [$plat], [$ten]);
    $this->assertListIsolated($hTen, '/tenant/exports', 'id', [$ten], [$plat]);
});

test('una importación hecha dentro de A no es visible desde B (aislamiento de DataImport)', function () {
    $user = $this->isoUser($this->a);
    $import = DataImport::create([
        'tenant_id' => $this->a->id, 'user_id' => $user->id, 'importer' => 'users', 'status' => 'previewed',
        'original_name' => 'x.csv', 'disk' => 'local', 'path' => 'x.csv', 'file_hash' => 'h',
    ]);

    expect(DataImport::query()->visibleTo($user->id, $this->a->id)->find($import->id))->not->toBeNull()
        ->and(DataImport::query()->visibleTo($user->id, $this->b->id)->find($import->id))->toBeNull()
        ->and(DataImport::query()->visibleTo($user->id, null)->find($import->id))->toBeNull();

    $export = DataExport::create(['tenant_id' => $this->a->id, 'user_id' => $user->id, 'exporter' => 'tenant-members', 'format' => 'csv']);
    expect(DataExport::query()->visibleTo($user->id, $this->b->id)->find($export->id))->toBeNull();
});

test('el job de exportación corre en el tenant de la exportación aunque el worker no tenga ninguno', function () {
    $export = DataExport::create(['tenant_id' => $this->b->id, 'user_id' => $this->ownerB->id, 'exporter' => 'tenant-members', 'format' => 'csv', 'filters' => [], 'status' => 'pending']);
    $this->ctx->clear();

    app()->call([new ExportJob($export->id), 'process']);

    $csv = exportFilas($export->fresh());
    expect($export->fresh()->path)->toContain('tenants/'.$this->b->id.'/exports/')
        ->and($csv)->toContain($this->ownerB->email)->and($csv)->not->toContain($this->ownerA->email)
        ->and($this->ctx->id())->toBeNull();
});

test('ser dueño en A no permite exportar los miembros de B siendo solo miembro de B', function () {
    // Con equipos de Spatie apagados el rol tenant-owner es global: pasa el permission: de la ruta en B.
    $doble = $this->isoUser($this->a, 'owner');
    $this->b->users()->attach($doble->getKey(), ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);

    $this->isoCall($this->isoHeaders($doble, $this->b), 'POST', '/tenant/exports/members', ['format' => 'csv'])
        ->assertForbidden();

    expect(DataExport::query()->withoutGlobalScopes()->where('user_id', $doble->id)->count())->toBe(0);
});

<?php

use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales: N procesos intentan confirmar la misma importación o reclamar la misma exportación a la
 * vez; exactamente uno gana. Los datos quedan comprometidos: se limpian a mano.
 */

afterEach(function () {
    DB::table('data_imports')->where('original_name', 'carrera.csv')->delete();
    DB::table('data_exports')->where('exporter', 'carrera')->delete();
    DB::table('users')->where('email', 'like', 'carrera-imp-%')->delete();
});

test('seis confirmaciones simultáneas de la misma importación: solo una pasa de previewed a queued', function () {
    $user = User::factory()->create(['email' => 'carrera-imp-'.bin2hex(random_bytes(3)).'@example.com']);
    $import = DataImport::create([
        'user_id' => $user->id, 'importer' => 'users', 'status' => 'previewed', 'original_name' => 'carrera.csv',
        'disk' => 'local', 'path' => 'x.csv', 'file_hash' => 'h', 'valid_rows' => 1,
    ]);
    $id = $import->id;

    $results = ConcurrentRunner::run(6, fn (int $i) => DataImport::findOrFail($id)->transition('previewed', 'queued'), 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->where('result', true)->count())->toBe(1, json_encode($results));
    expect(DataImport::findOrFail($id)->status)->toBe('queued');
});

test('seis workers que reclaman la misma exportación: solo uno la procesa', function () {
    $user = User::factory()->create(['email' => 'carrera-imp-'.bin2hex(random_bytes(3)).'@example.com']);
    $export = DataExport::create(['user_id' => $user->id, 'exporter' => 'carrera', 'format' => 'csv', 'status' => 'pending']);
    $id = $export->id;

    $results = ConcurrentRunner::run(6, fn (int $i) => DataExport::findOrFail($id)->claim(), 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->where('result', true)->count())->toBe(1, json_encode($results));
});

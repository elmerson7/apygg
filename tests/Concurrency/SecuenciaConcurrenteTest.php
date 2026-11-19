<?php

use App\Contracts\SequenceInterface;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales de numeración: N procesos piden número a la vez y deben recibir
 * 1..N sin huecos ni duplicados. Los datos quedan comprometidos: se limpian a mano.
 */

afterEach(function () {
    DB::table('sequences')->where('name', 'like', 'carrera-%')->delete();
    DB::table('tenants')->where('slug', 'like', 'carrera-seq-%')->delete();
});

test('diez procesos reciben números distintos y consecutivos, sin huecos', function () {
    $name = 'carrera-'.bin2hex(random_bytes(4));
    $n = 10;

    $results = ConcurrentRunner::run($n, fn (int $i) => app(SequenceInterface::class)->next($name), 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $numbers = collect($results)->pluck('result')->sort()->values()->all();

    expect($numbers)->toBe(range(1, $n), json_encode($results));
    expect((int) DB::table('sequences')->where('name', $name)->value('next_value'))->toBe($n + 1);
});

test('con prefijo y relleno los procesos obtienen cadenas únicas y ordenadas', function () {
    $name = 'carrera-'.bin2hex(random_bytes(4));

    $results = ConcurrentRunner::run(6, fn (int $i) => app(SequenceInterface::class)->next($name, null, ['prefix' => 'F-', 'padding' => 4]), 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $numbers = collect($results)->pluck('result')->sort()->values()->all();

    expect($numbers)->toBe(array_map(fn (int $k) => sprintf('F-%04d', $k), range(1, 6)));
});

test('dos tenants tienen numeraciones independientes bajo carga', function () {
    $a = Tenant::create(['name' => 'A', 'slug' => 'carrera-seq-a-'.bin2hex(random_bytes(3))]);
    $b = Tenant::create(['name' => 'B', 'slug' => 'carrera-seq-b-'.bin2hex(random_bytes(3))]);
    $name = 'carrera-'.bin2hex(random_bytes(4));
    $ids = [$a->id, $b->id];

    $results = ConcurrentRunner::run(8, fn (int $i) => [
        'tenant' => $ids[$i % 2],
        'n' => app(SequenceInterface::class)->next($name, $ids[$i % 2]),
    ], 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    foreach ($ids as $id) {
        $mine = collect($results)->filter(fn ($r) => $r['result']['tenant'] === $id)->pluck('result.n')->sort()->values()->all();
        expect($mine)->toBe(range(1, 4), json_encode($results));
    }
});

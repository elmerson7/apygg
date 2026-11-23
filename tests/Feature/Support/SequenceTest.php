<?php

use App\Contracts\SequenceInterface;
use App\Support\Sequences\SequenceExhausted;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

function seq(): SequenceInterface
{
    return app(SequenceInterface::class);
}

test('reparte enteros consecutivos desde 1 y crea la secuencia al primer uso', function () {
    expect(seq()->next('pedido'))->toBe(1)->and(seq()->next('pedido'))->toBe(2)->and(seq()->nextValue('pedido'))->toBe(3);
    expect(DB::table('sequences')->where('name', 'pedido')->count())->toBe(1);
});

test('las secuencias de distinto nombre son independientes', function () {
    seq()->next('a');
    seq()->next('a');

    expect(seq()->next('b'))->toBe(1);
});

test('aplica prefijo y relleno de la configuración', function () {
    config(['sequences.definitions.invoice' => ['prefix' => 'F-{year}-', 'padding' => 6, 'reset' => 'yearly']]);
    $year = now()->format('Y');

    expect(seq()->next('invoice'))->toBe("F-{$year}-000001")->and(seq()->next('invoice'))->toBe("F-{$year}-000002");
});

test('el reinicio anual numera aparte cada año', function () {
    config(['sequences.definitions.invoice' => ['prefix' => 'F-{year}-', 'padding' => 3, 'reset' => 'yearly']]);

    $this->travelTo(now()->setDate(2026, 12, 31));
    expect(seq()->next('invoice'))->toBe('F-2026-001')->and(seq()->next('invoice'))->toBe('F-2026-002');

    $this->travelTo(now()->setDate(2027, 1, 1));
    expect(seq()->next('invoice'))->toBe('F-2027-001');
});

test('peek no reserva ni crea la secuencia', function () {
    config(['sequences.definitions.t' => ['prefix' => 'T', 'padding' => 3]]);

    expect(seq()->peek('t'))->toBe('T001')->and(seq()->peek('t'))->toBe('T001');
    expect(DB::table('sequences')->where('name', 't')->exists())->toBeFalse();

    seq()->next('t');
    expect(seq()->peek('t'))->toBe('T002');
});

test('la definición pasada al crear manda sobre la configuración y luego manda la fila', function () {
    expect(seq()->next('x', null, ['prefix' => 'X', 'padding' => 2, 'start' => 10]))->toBe('X10');
    expect(seq()->next('x', null, ['prefix' => 'OTRO']))->toBe('X11');
});

test('lanza SequenceExhausted al pasar el máximo y no consume número', function () {
    $def = ['max' => 2];

    expect(seq()->next('lim', null, $def))->toBe(1)->and(seq()->next('lim'))->toBe(2);
    expect(fn () => seq()->next('lim'))->toThrow(SequenceExhausted::class);
    expect(DB::table('sequences')->where('name', 'lim')->value('next_value'))->toBe(3);
});

test('un número reservado en una transacción revertida vuelve a estar libre (sin huecos)', function () {
    seq()->next('tx');

    try {
        DB::transaction(function () {
            expect(seq()->next('tx'))->toBe(2);

            throw new RuntimeException('falla el documento');
        });
    } catch (RuntimeException) {
    }

    expect(seq()->next('tx'))->toBe(2);
});

test('con multiempresa activo usa el tenant del contexto y cada tenant numera aparte', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('seq-a');
    $b = $this->makeTenant('seq-b');
    $ctx = app(TenantContext::class);

    expect($ctx->run($a, fn () => [seq()->next('inv'), seq()->next('inv')]))->toBe([1, 2]);
    expect($ctx->run($b, fn () => seq()->next('inv')))->toBe(1);
    expect($ctx->run($a, fn () => seq()->next('inv')))->toBe(3);
    expect(DB::table('sequences')->where('tenant_id', $a->id)->value('next_value'))->toBe(4);
});

test('con multiempresa activo y sin tenant falla cerrado; un tenant explícito solo vale dentro de withoutTenant', function () {
    $this->enableTenancy();
    $t = $this->makeTenant('seq-c');

    expect(fn () => seq()->next('inv'))->toThrow(MissingTenantContext::class);
    expect(fn () => seq()->next('inv', $t->id))->toThrow(MissingTenantContext::class);
    expect(TenantQuery::withoutTenant(fn () => seq()->next('inv', $t->id), 'prueba'))->toBe(1);
});

test('con multiempresa activo un tenant explícito distinto del contexto lanza CrossTenantViolation y no consume número', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('seq-x');
    $b = $this->makeTenant('seq-y');
    $ctx = app(TenantContext::class);

    expect(fn () => $ctx->run($a, fn () => seq()->next('inv', $b->id)))->toThrow(CrossTenantViolation::class);
    expect(fn () => $ctx->run($a, fn () => seq()->nextValue('inv', $b->id)))->toThrow(CrossTenantViolation::class);
    expect(fn () => $ctx->run($a, fn () => seq()->peek('inv', $b->id)))->toThrow(CrossTenantViolation::class);
    expect(DB::table('sequences')->where('tenant_id', $b->id)->count())->toBe(0);
    // El propio tenant explícito (= contexto) sí se acepta.
    expect($ctx->run($a, fn () => seq()->next('inv', $a->id)))->toBe(1);
});

test('SequenceExhausted es una excepción de dominio 409 con code sequence_exhausted', function () {
    seq()->next('lim2', null, ['max' => 1]);

    app('router')->get('/__seq-agotada', fn () => seq()->next('lim2', null, ['max' => 1]));

    $this->getJson('/__seq-agotada')->assertStatus(409)->assertJsonPath('code', 'sequence_exhausted');
});

test('la restricción única impide dos filas globales con el mismo nombre', function () {
    seq()->next('glob');

    expect(fn () => DB::table('sequences')->insert([
        'tenant_id' => null, 'name' => 'glob', 'period' => '', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('al crear la secuencia guarda definición, fechas y avanza updated_at en cada reserva', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    seq()->next('fechas', null, ['prefix' => 'P-', 'padding' => 4, 'start' => 5, 'max' => 9]);

    $fila = DB::table('sequences')->where('name', 'fechas')->first();
    expect($fila->prefix)->toBe('P-')
        ->and($fila->padding)->toBe(4)
        ->and($fila->max_value)->toBe(9)
        ->and($fila->next_value)->toBe(6)
        ->and($fila->tenant_id)->toBeNull()
        ->and($fila->period)->toBe('')
        ->and($fila->created_at)->not->toBeNull()
        ->and($fila->updated_at)->not->toBeNull();

    $creada = $fila->created_at;
    $this->travel(5)->minutes();
    seq()->next('fechas');

    $despues = DB::table('sequences')->where('name', 'fechas')->first();
    expect($despues->created_at)->toBe($creada)
        ->and($despues->updated_at)->not->toBe($fila->updated_at);
});

test('normaliza la definición: relleno entre 0 y 20, inicio mínimo 1 y prefijo vacío como nulo', function () {
    config(['sequences.defaults' => []]);

    seq()->next('d-vacia');
    seq()->next('d-alto', null, ['padding' => 25, 'start' => 0]);
    seq()->next('d-bajo', null, ['padding' => -4, 'start' => -7, 'prefix' => '']);
    seq()->next('d-20', null, ['padding' => 20]);
    seq()->next('d-1', null, ['padding' => 1, 'start' => 1]);

    $f = fn (string $n) => DB::table('sequences')->where('name', $n)->first();

    expect($f('d-vacia')->padding)->toBe(0)
        ->and($f('d-vacia')->next_value)->toBe(2)
        ->and($f('d-vacia')->prefix)->toBeNull()
        ->and($f('d-vacia')->max_value)->toBeNull()
        ->and($f('d-alto')->padding)->toBe(20)
        ->and($f('d-alto')->next_value)->toBe(2)
        ->and($f('d-bajo')->padding)->toBe(0)
        ->and($f('d-bajo')->next_value)->toBe(2)
        ->and($f('d-bajo')->prefix)->toBeNull()
        ->and($f('d-20')->padding)->toBe(20)
        ->and($f('d-1')->padding)->toBe(1);
});

test('el relleno máximo es de 20 dígitos y el devuelto respeta la fila', function () {
    config(['sequences.defaults' => []]);

    expect(seq()->next('r20', null, ['padding' => 25, 'prefix' => 'N']))->toBe('N'.str_repeat('0', 19).'1')
        ->and(seq()->next('r1', null, ['padding' => 1]))->toBe('1')
        ->and(seq()->next('r0'))->toBe(1);
});

test('un prefijo vacío en la definición devuelve solo el número rellenado', function () {
    expect(seq()->next('pv', null, ['prefix' => '', 'padding' => 3]))->toBe('001');
});

test('un tenant explícito con multiempresa apagado numera aparte de la secuencia global', function () {
    $t = $this->makeTenant('seq-off');

    expect(seq()->next('glob2'))->toBe(1)
        ->and(seq()->next('glob2', $t->id))->toBe(1)
        ->and(seq()->next('glob2', $t->id))->toBe(2)
        ->and(seq()->next('glob2'))->toBe(2);
    expect(DB::table('sequences')->where('name', 'glob2')->where('tenant_id', $t->id)->value('next_value'))->toBe(3)
        ->and(DB::table('sequences')->where('name', 'glob2')->whereNull('tenant_id')->value('next_value'))->toBe(3);
});

test('peek con un tenant explícito lee la secuencia de ese tenant y usa el prefijo de la fila', function () {
    $t = $this->makeTenant('seq-peek');
    seq()->next('pk', $t->id, ['prefix' => 'A', 'padding' => 2]);

    expect(seq()->peek('pk', $t->id))->toBe('A02')
        ->and(seq()->peek('pk'))->toBe(1);
});

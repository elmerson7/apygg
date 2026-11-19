<?php

use App\Contracts\SequenceInterface;
use App\Support\Sequences\SequenceExhausted;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
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

test('con multiempresa activo y sin tenant falla cerrado; un tenant explícito sí funciona', function () {
    $this->enableTenancy();
    $t = $this->makeTenant('seq-c');

    expect(fn () => seq()->next('inv'))->toThrow(MissingTenantContext::class);
    expect(seq()->next('inv', $t->id))->toBe(1);
});

test('la restricción única impide dos filas globales con el mismo nombre', function () {
    seq()->next('glob');

    expect(fn () => DB::table('sequences')->insert([
        'tenant_id' => null, 'name' => 'glob', 'period' => '', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

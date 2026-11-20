<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Contracts\SequenceInterface;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->bootIsolation();
    $this->seq = app(SequenceInterface::class);
});

test('cada tenant lleva su propio contador', function () {
    $this->ctx->run($this->a, function () {
        expect($this->seq->nextValue('factura'))->toBe(1)
            ->and($this->seq->nextValue('factura'))->toBe(2)
            ->and($this->seq->nextValue('factura'))->toBe(3);
    });
    $this->ctx->run($this->b, fn () => expect($this->seq->nextValue('factura'))->toBe(1));
    $this->ctx->run($this->a, fn () => expect($this->seq->nextValue('factura'))->toBe(4));

    expect(DB::table('sequences')->where('name', 'factura')->pluck('next_value', 'tenant_id')->all())
        ->toEqual([$this->a->id => 5, $this->b->id => 2]);
});

test('peek desde A no ve ni crea el contador de B', function () {
    $this->ctx->run($this->b, fn () => $this->seq->nextValue('pedido'));
    $this->ctx->run($this->b, fn () => $this->seq->nextValue('pedido'));

    $this->ctx->run($this->a, fn () => expect($this->seq->peek('pedido'))->toBe(1));
    expect(DB::table('sequences')->where('name', 'pedido')->where('tenant_id', $this->a->id)->exists())->toBeFalse();
});

test('con multiempresa activa reservar un número sin tenant falla cerrado y sin tocar contadores', function () {
    $this->ctx->clear();

    expect(fn () => $this->seq->next('factura'))->toThrow(MissingTenantContext::class);
    expect(DB::table('sequences')->count())->toBe(0);
});

test('la secuencia global (sin tenant) solo existe dentro de withoutTenant y no se mezcla con las de tenant', function () {
    $this->ctx->run($this->a, fn () => $this->seq->nextValue('factura'));

    TenantQuery::withoutTenant(function () {
        expect($this->seq->nextValue('factura'))->toBe(1);
    }, 'test');

    expect(DB::table('sequences')->where('name', 'factura')->whereNull('tenant_id')->value('next_value'))->toBe(2)
        ->and(DB::table('sequences')->where('name', 'factura')->where('tenant_id', $this->a->id)->value('next_value'))->toBe(2);
});

/*
| `next()` acepta un $tenantId explícito, pero con aislamiento activo uno distinto del contexto es otro
| tenant: CrossTenantViolation y no se toca ningún contador. Solo las tareas de plataforma, dentro de
| withoutTenant, pueden numerar para un tenant concreto.
*/
test('un tenantId explícito distinto del contexto lanza CrossTenantViolation y no reserva en B', function () {
    expect(fn () => $this->ctx->run($this->a, fn () => $this->seq->nextValue('factura', $this->b->id)))
        ->toThrow(CrossTenantViolation::class);

    expect(DB::table('sequences')->where('name', 'factura')->exists())->toBeFalse();
});

test('dentro de withoutTenant un tenantId explícito sí numera para ese tenant (tarea de plataforma)', function () {
    TenantQuery::withoutTenant(fn () => $this->seq->nextValue('factura', $this->b->id), 'prueba de plataforma');

    expect(DB::table('sequences')->where('name', 'factura')->where('tenant_id', $this->b->id)->value('next_value'))->toBe(2)
        ->and(DB::table('sequences')->where('name', 'factura')->where('tenant_id', $this->a->id)->exists())->toBeFalse();
});

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Services\CacheService;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantNote;
use Tests\Support\TenantNoteJob;

beforeEach(function () {
    $this->bootIsolation();
    config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
    $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'nota A']));
    $this->ctx->run($this->b, fn () => TenantNote::create(['title' => 'nota B']));
    $this->trabajar = fn () => Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--stop-when-empty' => true]);
    $this->encolar = fn (string $clave) => app(Dispatcher::class)->dispatch(new TenantNoteJob($clave));
});

// ── Jobs ────────────────────────────────────────────────────────────────────

test('un job despachado en A guarda el tenant en el Context y en el worker corre con A y solo ve lo de A', function () {
    $this->ctx->run($this->a, function () {
        expect(Context::getHidden('tenant_id'))->toBe($this->a->id);
        ($this->encolar)('job-a');
    });
    $this->ctx->clear();
    expect(DB::table('jobs')->count())->toBe(1);

    ($this->trabajar)();

    expect(Cache::get('job-a'))->toBe(['tenant_id' => $this->a->id, 'titles' => ['nota A'], 'error' => null])
        ->and(app(TenantContext::class)->id())->toBeNull();
});

test('dos jobs de tenants distintos en el mismo worker no se contagian el contexto', function () {
    $this->ctx->run($this->b, fn () => ($this->encolar)('job-b'));
    $this->ctx->run($this->a, fn () => ($this->encolar)('job-a'));
    $this->ctx->clear();

    ($this->trabajar)();
    ($this->trabajar)();

    expect(Cache::get('job-b')['titles'])->toBe(['nota B'])
        ->and(Cache::get('job-a')['titles'])->toBe(['nota A']);
});

test('un job despachado sin tenant con multiempresa activa falla cerrado al tocar un modelo de tenant', function () {
    ($this->encolar)('job-sin');

    ($this->trabajar)();

    expect(Cache::get('job-sin'))->toBe(['tenant_id' => null, 'titles' => [], 'error' => MissingTenantContext::class]);
});

// ── Caché ───────────────────────────────────────────────────────────────────

test('una clave escrita por A en CacheService no la lee B', function () {
    $this->ctx->run($this->a, fn () => CacheService::set('informe', 'datos de A', 60));

    $this->ctx->run($this->a, fn () => expect(CacheService::get('informe'))->toBe('datos de A'));
    $this->ctx->run($this->b, fn () => expect(CacheService::get('informe'))->toBeNull());
    $this->ctx->run($this->b, fn () => expect(CacheService::has('informe'))->toBeFalse());
});

test('olvidar o sobrescribir una clave en B no afecta a la de A', function () {
    $this->ctx->run($this->a, fn () => CacheService::set('informe', 'A', 60));
    $this->ctx->run($this->b, fn () => CacheService::set('informe', 'B', 60));
    $this->ctx->run($this->b, fn () => CacheService::forget('informe'));

    $this->ctx->run($this->a, fn () => expect(CacheService::get('informe'))->toBe('A'));
    $this->ctx->run($this->b, fn () => expect(CacheService::get('informe'))->toBeNull());
});

test('remember en B no devuelve el valor calculado en A', function () {
    $this->ctx->run($this->a, fn () => CacheService::remember('total', 60, fn () => 'A'));

    $this->ctx->run($this->b, fn () => expect(CacheService::remember('total', 60, fn () => 'B'))->toBe('B'));
    $this->ctx->run($this->a, fn () => expect(CacheService::remember('total', 60, fn () => 'otro'))->toBe('A'));
});

test('la clave de tenant se prefija con t<id> y dentro de withoutTenant es global', function () {
    $this->ctx->run($this->a, fn () => CacheService::set('informe', 'A', 60));

    expect(Cache::get('apygg:t'.$this->a->id.':informe'))->toBe('A')
        ->and(Cache::get('apygg:informe'))->toBeNull();

    TenantQuery::withoutTenant(fn () => CacheService::set('informe', 'global', 60), 'test');
    expect(Cache::get('apygg:informe'))->toBe('global');
    $this->ctx->run($this->b, fn () => expect(CacheService::get('informe'))->toBeNull());
});

// ── Plantilla ───────────────────────────────────────────────────────────────

test('el helper assertModelIsolated detecta el aislamiento de un modelo de tenant', function () {
    TenantNote::query()->withoutGlobalScopes()->delete();

    $this->assertModelIsolated(TenantNote::class, fn ($tenant) => TenantNote::create(['title' => 'x '.$tenant->slug]));
});

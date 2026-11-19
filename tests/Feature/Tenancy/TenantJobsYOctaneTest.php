<?php

use App\Listeners\ResetTenantContext;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;
use Tests\Support\TenantNoteJob;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/** Encola YA (un PendingDispatch devuelto por un closure se encolaría al destruirse, fuera del contexto). */
function dispatchNow_(string $key): void
{
    app(Dispatcher::class)->dispatch(new TenantNoteJob($key));
}

beforeEach(function () {
    $this->enableTenancy();
    config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'nota A']));
    $this->ctx->run($this->b, fn () => TenantNote::create(['title' => 'nota B']));

    $this->work = function (): void {
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--stop-when-empty' => true]);
    };
});

test('el job encolado restaura el tenant con el que se encoló', function () {
    $this->ctx->run($this->a, fn () => dispatchNow_('r1'));
    $this->ctx->clear();
    expect(DB::table('jobs')->count())->toBe(1);

    ($this->work)();

    expect(Cache::get('r1'))->toBe(['tenant_id' => $this->a->id, 'titles' => ['nota A'], 'error' => null]);
});

test('el tenant de un job no se filtra al siguiente job ni a la petición', function () {
    $this->ctx->run($this->a, fn () => dispatchNow_('ra'));
    $this->ctx->run($this->b, fn () => dispatchNow_('rb'));
    $this->ctx->clear();

    ($this->work)();
    ($this->work)();

    expect(Cache::get('ra')['titles'])->toBe(['nota A'])
        ->and(Cache::get('rb')['titles'])->toBe(['nota B'])
        ->and(app(TenantContext::class)->current())->toBeNull();
});

test('un job encolado sin tenant que toca un modelo con tenant falla cerrado', function () {
    dispatchNow_('rs');

    ($this->work)();

    expect(Cache::get('rs'))->toBe(['tenant_id' => null, 'titles' => [], 'error' => MissingTenantContext::class]);
});

test('un job de un tenant que ya no existe falla en vez de correr sin contexto', function () {
    $this->ctx->run($this->a, fn () => dispatchNow_('rx'));
    $this->ctx->clear();
    DB::table('tenant_notes')->where('tenant_id', $this->a->id)->delete();
    DB::table('tenants')->where('id', $this->a->id)->delete();

    ($this->work)();

    expect(Cache::get('rx'))->toBeNull()
        ->and(DB::table('failed_jobs')->count() + DB::table('jobs')->count())->toBe(1);
});

test('el tenant fijado viaja en el Context oculto (no sale en los logs)', function () {
    $this->ctx->set($this->a);

    expect(Context::getHidden('tenant_id'))->toBe($this->a->id)
        ->and(Context::all())->not->toHaveKey('tenant_id');

    $this->ctx->clear();

    expect(Context::getHidden('tenant_id'))->toBeNull();
});

test('al recrear los scoped (Octane) el contexto de tenant empieza vacío', function () {
    $this->ctx->set($this->a);

    app()->forgetScopedInstances();

    expect(app(TenantContext::class)->current())->toBeNull()
        ->and(app(TenantContext::class))->not->toBe($this->ctx);
});

test('el listener de Octane limpia el tenant al recibir una petición', function () {
    $this->ctx->set($this->a);

    (new ResetTenantContext)->handle(new class
    {
        public $sandbox;
    });

    expect(app(TenantContext::class)->current())->toBeNull();
});

test('TenantContext está registrado como scoped y no como singleton', function () {
    $bindings = app()->getBindings();

    expect(app()->isShared(TenantContext::class))->toBeTrue();
    expect(app()->make(TenantContext::class))->toBe(app()->make(TenantContext::class));
});

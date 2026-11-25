<?php

use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

/*
 * Hooks de BelongsToTenant sobre un modelo suelto (create, save, delete, hidratación) en los casos límite que las
 * pruebas de mutación mostraron sin cubrir: modo plataforma, tenant ajeno al crear, tenant_id como texto,
 * mover un registro con save() y borrar uno ajeno cambiando antes tenant_id en memoria.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class, AssertsTenantIsolation::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->bootIsolation();
});

test('en modo plataforma dentro de un tenant crear sin tenant_id usa el del contexto', function () {
    $nota = $this->ctx->run($this->a, fn () => TenantQuery::withoutTenant(fn () => TenantNote::query()->create(['title' => 'plataforma'])));

    expect((int) DB::table('tenant_notes')->where('id', $nota->id)->value('tenant_id'))->toBe($this->a->id);
});

test('en modo plataforma sin tenant en el contexto crear sin tenant_id falla cerrado', function () {
    expect(fn () => TenantQuery::withoutTenant(fn () => TenantNote::query()->create(['title' => 'huerfana'])))
        ->toThrow(MissingTenantContext::class);

    expect(DB::table('tenant_notes')->count())->toBe(0);
});

test('crear con el tenant_id de otro tenant se rechaza y crear con el propio como texto se acepta', function () {
    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'fuga', 'tenant_id' => $this->b->id])))
        ->toThrow(CrossTenantViolation::class);

    $propia = $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'propia', 'tenant_id' => (string) $this->a->id]));

    expect(DB::table('tenant_notes')->where('title', 'fuga')->count())->toBe(0)
        ->and((int) DB::table('tenant_notes')->where('id', $propia->id)->value('tenant_id'))->toBe($this->a->id);
});

test('guardar un registro con otro tenant_id se rechaza y no lo mueve', function () {
    $nota = $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'mia']));

    expect(fn () => $this->ctx->run($this->a, function () use ($nota) {
        $nota->tenant_id = $this->b->id;
        $nota->save();
    }))->toThrow(CrossTenantViolation::class);

    expect((int) DB::table('tenant_notes')->where('id', $nota->id)->value('tenant_id'))->toBe($this->a->id);
});

test('borrar un registro ajeno cambiándole antes tenant_id en memoria se rechaza', function () {
    $ajena = $this->ctx->run($this->b, fn () => TenantNote::query()->create(['title' => 'de B']));

    expect(fn () => $this->ctx->run($this->a, function () use ($ajena) {
        $ajena->tenant_id = $this->a->id;
        $ajena->delete();
    }))->toThrow(CrossTenantViolation::class);

    expect(DB::table('tenant_notes')->where('id', $ajena->id)->whereNull('deleted_at')->count())->toBe(1);
});

test('hidratar un registro propio con tenant_id como texto no se toma por ajeno', function () {
    $nota = $this->ctx->run($this->a, fn () => (new TenantNote)->newFromBuilder(['id' => 1, 'tenant_id' => (string) $this->a->id, 'title' => 'x']));

    expect($nota->title)->toBe('x');

    expect(fn () => $this->ctx->run($this->a, fn () => (new TenantNote)->newFromBuilder(['id' => 2, 'tenant_id' => (string) $this->b->id, 'title' => 'y'])))
        ->toThrow(CrossTenantViolation::class);
});

test('belongsToTenant compara el tenant del registro aunque el id llegue como texto', function () {
    $nota = $this->ctx->run($this->a, fn () => (new TenantNote)->newFromBuilder(['id' => 1, 'tenant_id' => (string) $this->a->id, 'title' => 'mia']));

    expect($nota->belongsToTenant($this->a->id))->toBeTrue()
        ->and($nota->belongsToTenant((string) $this->a->id))->toBeTrue()
        ->and($nota->belongsToTenant($this->b->id))->toBeFalse();
});

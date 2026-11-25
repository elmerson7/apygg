<?php

use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

/*
 * Casos límite de TenantEloquentBuilder (escrituras masivas con multiempresa) que las pruebas de mutación
 * mostraron sin cubrir: upsert vacío o con la bandera apagada, uniqueBy como texto, `$update` asociativo,
 * columnas a actualizar por defecto, filas con clave de texto, modo plataforma con tenant en el contexto
 * y tenant_id propio enviado como texto.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class, AssertsTenantIsolation::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->bootIsolation();
});

function notasDelTenant($test, $tenant): array
{
    return $test->ctx->run($tenant, fn () => TenantNote::query()->orderBy('id')->pluck('title')->all());
}

function indiceUnicoPorTenantYTitulo(): void
{
    DB::statement('create unique index tenant_notes_tenant_title on tenant_notes (tenant_id, title)');
}

test('un upsert sin filas no exige tenant_id en uniqueBy y no escribe nada', function () {
    $afectadas = $this->ctx->run($this->a, fn () => TenantNote::query()->upsert([], ['id']));

    expect($afectadas)->toBe(0)
        ->and(DB::table('tenant_notes')->count())->toBe(0);
});

test('con multiempresa apagado un upsert por id funciona sin tenant_id en uniqueBy', function () {
    $nota = $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'antes']));
    config(['multi-tenancy.enabled' => false]);
    $now = now();

    $afectadas = TenantNote::query()->upsert(
        [['id' => $nota->id, 'tenant_id' => $this->a->id, 'title' => 'despues', 'created_at' => $now, 'updated_at' => $now]],
        ['id'],
        ['title'],
    );

    expect($afectadas)->toBe(1)
        ->and(DB::table('tenant_notes')->where('id', $nota->id)->value('title'))->toBe('despues');
});

test('un upsert con uniqueBy como texto sin tenant_id se rechaza', function () {
    $now = now();

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['id' => 1, 'title' => 'x', 'created_at' => $now, 'updated_at' => $now]],
        'id',
    )))->toThrow(CrossTenantViolation::class);
});

test('un upsert por tenant no puede actualizar tenant_id ni como columna ni como expresión', function () {
    indiceUnicoPorTenantYTitulo();
    $now = now();
    $fila = [['title' => 'x', 'created_at' => $now, 'updated_at' => $now]];

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert($fila, ['tenant_id', 'title'], ['title', 'tenant_id'])))
        ->toThrow(CrossTenantViolation::class);

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert($fila, ['tenant_id', 'title'], ['updated_at', 'tenant_id' => DB::raw('excluded.tenant_id')])))
        ->toThrow(CrossTenantViolation::class);

    expect(DB::table('tenant_notes')->count())->toBe(0);
});

test('un upsert sin columnas a actualizar actualiza las demás pero nunca tenant_id ni las de uniqueBy', function () {
    indiceUnicoPorTenantYTitulo();
    $antes = now()->subDay()->startOfSecond();
    $despues = now()->startOfSecond();
    $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'compartido', 'created_at' => $antes, 'updated_at' => $antes]));

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        if (str_contains(strtolower($query->sql), 'on conflict')) {
            $sql[] = $query->sql;
        }
    });

    $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['title' => 'compartido', 'created_at' => $antes, 'updated_at' => $despues]],
        ['tenant_id', 'title'],
    ));

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('"updated_at" = "excluded"."updated_at"')
        ->and($sql[0])->not->toContain('"tenant_id" = "excluded"')
        ->and($sql[0])->not->toContain('"title" = "excluded"')
        ->and(DB::table('tenant_notes')->where('title', 'compartido')->count())->toBe(1)
        ->and(DB::table('tenant_notes')->where('title', 'compartido')->value('updated_at'))->toStartWith($despues->format('Y-m-d H:i:s'));
});

test('un upsert con filas de clave de texto y sin columnas a actualizar usa las columnas de las filas', function () {
    indiceUnicoPorTenantYTitulo();
    $antes = now()->subDay()->startOfSecond();
    $despues = now()->startOfSecond();
    $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'uno', 'created_at' => $antes, 'updated_at' => $antes]));

    $this->ctx->run($this->a, fn () => TenantNote::query()->upsert([
        'primera' => ['title' => 'uno', 'created_at' => $antes, 'updated_at' => $despues],
        'segunda' => ['title' => 'dos', 'created_at' => $despues, 'updated_at' => $despues],
    ], ['tenant_id', 'title']));

    expect(notasDelTenant($this, $this->a))->toBe(['uno', 'dos'])
        ->and(DB::table('tenant_notes')->where('title', 'uno')->value('updated_at'))->toStartWith($despues->format('Y-m-d H:i:s'));
});

test('un insert masivo con filas de clave de texto rellena el tenant en cada fila', function () {
    $now = now();

    $this->ctx->run($this->a, fn () => TenantNote::query()->insert([
        'primera' => ['title' => 'clave-1', 'created_at' => $now, 'updated_at' => $now],
        'segunda' => ['title' => 'clave-2', 'created_at' => $now, 'updated_at' => $now],
    ]));

    expect(notasDelTenant($this, $this->a))->toBe(['clave-1', 'clave-2'])
        ->and(notasDelTenant($this, $this->b))->toBe([]);
});

test('en modo plataforma dentro de un tenant las filas sin tenant_id toman el del contexto y las demás conservan el suyo', function () {
    $now = now();

    $this->ctx->run($this->a, fn () => TenantQuery::withoutTenant(fn () => TenantNote::query()->insert([
        ['title' => 'explicita-b', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now],
        ['title' => 'del-contexto', 'created_at' => $now, 'updated_at' => $now],
    ])));

    expect(notasDelTenant($this, $this->a))->toBe(['del-contexto'])
        ->and(notasDelTenant($this, $this->b))->toBe(['explicita-b']);
});

test('un insert masivo acepta el tenant_id propio aunque llegue como texto', function () {
    $now = now();

    $this->ctx->run($this->a, fn () => TenantNote::query()->insert([
        ['title' => 'propia', 'tenant_id' => (string) $this->a->id, 'created_at' => $now, 'updated_at' => $now],
    ]));

    expect(notasDelTenant($this, $this->a))->toBe(['propia']);
});

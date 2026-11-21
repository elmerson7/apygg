<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Tests\Support\ActsWithHeaders;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\SyncDemoItem;
use Tests\Support\SyncDemoPolicy;

/*
 * Módulo `offline-sync` (docs/offline-sync.md): ID del cliente, pull con borrados, ETag/If-Match y POST /sync/batch
 * con idempotencia por operación, permisos por recurso y aislamiento entre tenants.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();

    Schema::create('sync_demo_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
        $table->string('title');
        $table->jsonb('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    config([
        'offline-sync.enabled' => true,
        'offline-sync.resources' => ['items' => [
            'model' => SyncDemoItem::class,
            'permissions' => ['viewAny' => 'syncdemo.read', 'create' => 'syncdemo.write', 'update' => 'syncdemo.write', 'delete' => 'syncdemo.delete'],
            'create_rules' => ['title' => 'required|string|max:100'],
        ]],
    ]);

    // Las rutas del módulo solo se registran con OFFLINE_SYNC_ENABLED: se recargan con el flag encendido.
    app('router')->setRoutes(new RouteCollection);
    Route::middleware('api')->group(base_path('routes/api.php'));
    app('router')->getRoutes()->refreshNameLookups();

    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');

    foreach (['syncdemo.read', 'syncdemo.write', 'syncdemo.delete'] as $p) {
        syncPermiso($p);
    }

    $this->ana = syncUsuario($this->a, ['syncdemo.read', 'syncdemo.write', 'syncdemo.delete']);
    $this->beto = syncUsuario($this->b, ['syncdemo.read', 'syncdemo.write', 'syncdemo.delete']);
});

function syncPermiso(string $name): Permission
{
    return Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['display_name' => $name, 'resource' => 'syncdemo', 'action' => explode('.', $name)[1]]);
}

function syncUsuario($tenant, array $permisos): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());
    $user->givePermissionTo($permisos);
    test()->addMember($tenant, $user);

    return $user;
}

function syncH(User $user, $tenant): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $tenant->id];
}

/** @param list<array<string, mixed>> $ops */
function syncBatch(User $user, $tenant, array $ops)
{
    return test()->asHeaders(syncH($user, $tenant))->postJson('/sync/batch', ['operations' => $ops]);
}

function syncOp(string $op, array $extra = []): array
{
    return ['idempotency_key' => 'k-'.Str::random(12), 'resource' => 'items', 'op' => $op, ...$extra];
}

function syncItemEn($tenant, string $title = 'Algo'): SyncDemoItem
{
    return app(TenantContext::class)->run($tenant, fn () => SyncDemoItem::createFromClient(['title' => $title]));
}

// ── IDs del cliente ────────────────────────────────────────────────────────

test('crear con un UUID v7 del cliente conserva ese id y responde 201 con su ETag', function () {
    $id = (string) Str::uuid7();

    $r = syncBatch($this->ana, $this->a, [syncOp('create', ['id' => $id, 'data' => ['title' => 'Primera']])])->assertOk();

    expect($r->json('data.succeeded'))->toBe(1)
        ->and($r->json('data.results.0.status'))->toBe(201)
        ->and($r->json('data.results.0.id'))->toBe($id)
        ->and($r->json('data.results.0.data.title'))->toBe('Primera')
        ->and($r->json('data.results.0.etag'))->toStartWith('"')
        ->and(DB::table('sync_demo_items')->where('id', $id)->value('tenant_id'))->toBe($this->a->id);
});

test('sin id el servidor genera un UUID v7', function () {
    $r = syncBatch($this->ana, $this->a, [syncOp('create', ['data' => ['title' => 'Sin id']])]);

    $id = $r->json('data.results.0.id');
    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

test('un id que no es UUID o es de una versión no aceptada se rechaza con invalid_client_id', function () {
    config(['offline-sync.client_id_versions' => [7]]);

    $r = syncBatch($this->ana, $this->a, [
        syncOp('create', ['id' => 'no-es-uuid', 'data' => ['title' => 'x']]),
        syncOp('create', ['id' => (string) Str::uuid(), 'data' => ['title' => 'v4']]), // v4 no aceptado
    ])->assertOk();

    expect($r->json('data.results.0.error.code'))->toBe('invalid_client_id')
        ->and($r->json('data.results.0.status'))->toBe(422)
        ->and($r->json('data.results.1.error.code'))->toBe('invalid_client_id')
        ->and(DB::table('sync_demo_items')->count())->toBe(0);
});

test('un id repetido en el mismo tenant y uno de otro tenant dan la misma respuesta (no revela existencia)', function () {
    $ajeno = syncItemEn($this->b, 'De Beta');
    $propio = syncItemEn($this->a, 'De Acme');

    $contraAjeno = syncBatch($this->ana, $this->a, [syncOp('create', ['id' => $ajeno->id, 'data' => ['title' => 'x']])])->json('data.results.0');
    $contraPropio = syncBatch($this->ana, $this->a, [syncOp('create', ['id' => $propio->id, 'data' => ['title' => 'x']])])->json('data.results.0');

    expect($contraAjeno['status'])->toBe(409)
        ->and($contraAjeno['error'])->toBe($contraPropio['error'])
        ->and($contraAjeno['error']['code'])->toBe('client_id_conflict')
        ->and(json_encode($contraAjeno))->not->toContain('Beta')
        // Y el registro ajeno sigue intacto.
        ->and(DB::table('sync_demo_items')->where('id', $ajeno->id)->value('title'))->toBe('De Beta');
});

// ── Permisos y aislamiento ─────────────────────────────────────────────────

test('sin permiso la operacion falla con 403 y las demas del lote siguen', function () {
    $lector = syncUsuario($this->a, ['syncdemo.read']);

    $r = syncBatch($lector, $this->a, [
        syncOp('create', ['data' => ['title' => 'No debería']]),
        syncOp('delete', ['id' => (string) Str::uuid7()]),
    ])->assertOk();

    expect($r->json('data.failed'))->toBe(2)
        ->and($r->json('data.results.0.status'))->toBe(403)
        ->and($r->json('data.results.0.error.code'))->toBe('forbidden')
        ->and(DB::table('sync_demo_items')->count())->toBe(0);
});

test('un recurso sin permisos ni policy declarados se deniega (fail-closed) y uno desconocido da 404', function () {
    config(['offline-sync.resources.cerrado' => ['model' => SyncDemoItem::class, 'create_rules' => ['title' => 'required']]]);

    $r = syncBatch($this->ana, $this->a, [
        syncOp('create', ['resource' => 'cerrado', 'data' => ['title' => 'x']]),
        syncOp('create', ['resource' => 'inexistente', 'data' => ['title' => 'x']]),
    ]);

    expect($r->json('data.results.0.status'))->toBe(403)
        ->and($r->json('data.results.1.error.code'))->toBe('sync_resource_unknown');
});

test('la policy del modelo tambien se aplica (ademas del permiso)', function () {
    $item = syncItemEn($this->a);
    Gate::policy(SyncDemoItem::class, SyncDemoPolicy::class);

    $r = syncBatch($this->ana, $this->a, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'Cambio']]),
        syncOp('delete', ['id' => $item->id]),
    ]);

    expect($r->json('data.results.0.status'))->toBe(403)
        ->and($r->json('data.results.1.status'))->toBe(200);
});

test('otro tenant no puede actualizar ni borrar lo mio: 404 igual que un id inexistente', function () {
    $item = syncItemEn($this->a, 'Mío');

    $r = syncBatch($this->beto, $this->b, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'Robado']]),
        syncOp('delete', ['id' => $item->id]),
        syncOp('update', ['id' => (string) Str::uuid7(), 'data' => ['title' => 'x']]),
    ]);

    expect($r->json('data.results.0.status'))->toBe(404)
        ->and($r->json('data.results.1.status'))->toBe(404)
        ->and($r->json('data.results.0.error'))->toBe($r->json('data.results.2.error'))
        ->and(DB::table('sync_demo_items')->where('id', $item->id)->value('title'))->toBe('Mío')
        ->and(DB::table('sync_demo_items')->where('id', $item->id)->value('deleted_at'))->toBeNull();
});

test('el pull de un tenant no ve los registros del otro', function () {
    syncItemEn($this->a, 'Acme 1');
    syncItemEn($this->b, 'Beta 1');

    $titulos = fn ($r) => collect($r->json('data'))->pluck('title')->all();

    expect($titulos($this->asHeaders(syncH($this->ana, $this->a))->getJson('/sync/items')->assertOk()))->toBe(['Acme 1'])
        ->and($titulos($this->asHeaders(syncH($this->beto, $this->b))->getJson('/sync/items')->assertOk()))->toBe(['Beta 1']);
});

test('sin sesion el lote da 401 y sin el flag no hay rutas', function () {
    $this->postJson('/sync/batch', ['operations' => []])->assertUnauthorized();

    config(['offline-sync.enabled' => false]);
    app('router')->setRoutes(new RouteCollection);
    Route::middleware('api')->group(base_path('routes/api.php'));
    app('router')->getRoutes()->refreshNameLookups();

    $this->asHeaders(syncH($this->ana, $this->a))->postJson('/sync/batch', ['operations' => []])->assertNotFound();
});

// ── Actualizar, borrar y concurrencia optimista ────────────────────────────

test('actualizar y borrar funcionan y el borrado es idempotente', function () {
    $item = syncItemEn($this->a, 'Original');

    $r = syncBatch($this->ana, $this->a, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'Editado']]),
        syncOp('delete', ['id' => $item->id]),
        syncOp('delete', ['id' => $item->id]), // ya borrado: sigue siendo 200
    ]);

    expect($r->json('data.results.0.data.title'))->toBe('Editado')
        ->and($r->json('data.results.1.data'))->toHaveKeys(['id', 'deleted_at'])
        ->and($r->json('data.results.2.status'))->toBe(200)
        ->and(DB::table('sync_demo_items')->where('id', $item->id)->value('deleted_at') !== null)->toBeTrue();
});

test('una validacion fallida da 422 con los errores y no toca nada', function () {
    $r = syncBatch($this->ana, $this->a, [syncOp('create', ['data' => ['title' => '']])]);

    expect($r->json('data.results.0.status'))->toBe(422)
        ->and($r->json('data.results.0.error.errors.title'))->not->toBeEmpty()
        ->and(DB::table('sync_demo_items')->count())->toBe(0);
});

test('If-Match con la version vieja da 412 precondition_failed y con la actual aplica el cambio', function () {
    $item = syncItemEn($this->a, 'v1');

    $creado = syncBatch($this->ana, $this->a, [syncOp('update', ['id' => $item->id, 'data' => ['title' => 'v2']])])->json('data.results.0');
    $etagV2 = $creado['etag'];

    // Otro dispositivo modifica mientras tanto.
    syncBatch($this->ana, $this->a, [syncOp('update', ['id' => $item->id, 'data' => ['title' => 'v3']])]);

    $r = syncBatch($this->ana, $this->a, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'mio'], 'if_match' => $etagV2]),
    ])->json('data.results.0');

    expect($r['status'])->toBe(412)
        ->and($r['error']['code'])->toBe('precondition_failed')
        ->and($r['error']['current_etag'])->toStartWith('"')
        ->and(DB::table('sync_demo_items')->where('id', $item->id)->value('title'))->toBe('v3');

    $bien = syncBatch($this->ana, $this->a, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'resuelto'], 'if_match' => $r['error']['current_etag']]),
    ])->json('data.results.0');

    expect($bien['status'])->toBe(200)->and($bien['data']['title'])->toBe('resuelto');
});

test('con require_if_match, sin If-Match da 428 precondition_required', function () {
    config(['offline-sync.resources.items.require_if_match' => true]);
    $item = syncItemEn($this->a);

    $r = syncBatch($this->ana, $this->a, [
        syncOp('update', ['id' => $item->id, 'data' => ['title' => 'x']]),
        syncOp('delete', ['id' => $item->id]),
    ]);

    expect($r->json('data.results.0.status'))->toBe(428)
        ->and($r->json('data.results.0.error.code'))->toBe('precondition_required')
        ->and($r->json('data.results.1.status'))->toBe(428);
});

// ── Idempotencia por operación ─────────────────────────────────────────────

test('repetir la misma clave repite el resultado guardado sin volver a ejecutar', function () {
    $op = syncOp('create', ['id' => (string) Str::uuid7(), 'data' => ['title' => 'Una vez']]);

    $primero = syncBatch($this->ana, $this->a, [$op])->json('data.results.0');
    $segundo = syncBatch($this->ana, $this->a, [$op])->json('data.results.0');

    expect($primero['replayed'])->toBeFalse()
        ->and($segundo['replayed'])->toBeTrue()
        ->and($segundo['status'])->toBe(201)
        ->and($segundo['id'])->toBe($primero['id'])
        ->and(DB::table('sync_demo_items')->count())->toBe(1);
});

test('la misma clave con otra operacion da idempotency_key_mismatch y no ejecuta', function () {
    $op = syncOp('create', ['data' => ['title' => 'A']]);
    syncBatch($this->ana, $this->a, [$op]);

    $r = syncBatch($this->ana, $this->a, [[...$op, 'data' => ['title' => 'B']]])->json('data.results.0');

    expect($r['status'])->toBe(422)
        ->and($r['error']['code'])->toBe('idempotency_key_mismatch')
        ->and(DB::table('sync_demo_items')->count())->toBe(1);
});

test('el mismo texto de clave en dos tenants o usuarios no se mezcla', function () {
    $mismaClave = 'clave-compartida';

    syncBatch($this->ana, $this->a, [['idempotency_key' => $mismaClave, 'resource' => 'items', 'op' => 'create', 'data' => ['title' => 'De Ana']]]);
    $deBeto = syncBatch($this->beto, $this->b, [['idempotency_key' => $mismaClave, 'resource' => 'items', 'op' => 'create', 'data' => ['title' => 'De Beto']]])->json('data.results.0');

    expect($deBeto['replayed'])->toBeFalse()
        ->and($deBeto['data']['title'])->toBe('De Beto')
        ->and(DB::table('sync_demo_items')->count())->toBe(2);
});

test('un lote con una operacion fallida deja pasar las demas', function () {
    $r = syncBatch($this->ana, $this->a, [
        syncOp('create', ['data' => ['title' => 'Bien 1']]),
        syncOp('create', ['data' => ['title' => '']]),
        syncOp('create', ['data' => ['title' => 'Bien 2']]),
    ]);

    expect($r->json('data.succeeded'))->toBe(2)
        ->and($r->json('data.failed'))->toBe(1)
        ->and(DB::table('sync_demo_items')->count())->toBe(2);
});

test('el lote valida su forma: tamano maximo, clave obligatoria y claves repetidas', function () {
    config(['offline-sync.max_operations' => 2]);

    $h = syncH($this->ana, $this->a);
    $op = fn () => syncOp('create', ['data' => ['title' => 'x']]);

    $this->asHeaders($h)->postJson('/sync/batch', ['operations' => [$op(), $op(), $op()]])->assertUnprocessable();
    $this->asHeaders($h)->postJson('/sync/batch', ['operations' => [['resource' => 'items', 'op' => 'create']]])->assertUnprocessable();
    $mismo = $op();
    $this->asHeaders($h)->postJson('/sync/batch', ['operations' => [$mismo, $mismo]])->assertUnprocessable();
    $this->asHeaders($h)->postJson('/sync/batch', ['operations' => []])->assertUnprocessable();
});

// ── Pull con updated_since ─────────────────────────────────────────────────

test('el pull devuelve cambios desde la marca, borrados como tombstone, orden estable y server_time', function () {
    $viejo = syncItemEn($this->a, 'Viejo');
    $nuevo = syncItemEn($this->a, 'Nuevo');
    $borrado = syncItemEn($this->a, 'Borrado');

    $base = Carbon::parse('2026-11-01 10:00:00');
    DB::table('sync_demo_items')->where('id', $viejo->id)->update(['updated_at' => $base]);
    DB::table('sync_demo_items')->where('id', $nuevo->id)->update(['updated_at' => $base->copy()->addDay()]);
    DB::table('sync_demo_items')->where('id', $borrado->id)->update(['updated_at' => $base->copy()->addDays(2), 'deleted_at' => $base->copy()->addDays(2)]);

    $h = syncH($this->ana, $this->a);

    $todo = $this->asHeaders($h)->getJson('/sync/items')->assertOk();
    expect(collect($todo->json('data'))->pluck('id')->all())->toBe([$viejo->id, $nuevo->id, $borrado->id]);

    $r = $this->asHeaders($h)->getJson('/sync/items?updated_since='.urlencode('2026-11-02T00:00:00+00:00'))->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$nuevo->id, $borrado->id])
        ->and($r->json('data.1'))->toHaveKeys(['id', 'deleted_at'])
        ->and($r->json('data.1'))->not->toHaveKey('title')
        ->and($r->json('data.0.title'))->toBe('Nuevo')
        ->and($r->json('meta.server_time'))->toEndWith('Z')
        ->and($r->json('meta'))->toHaveKeys(['has_more', 'next_cursor']);
});

test('el pull pagina por cursor sin repetir ni saltar filas con la misma marca de tiempo', function () {
    $mismoInstante = Carbon::parse('2026-11-01 10:00:00');
    $ids = [];

    foreach (range(1, 5) as $i) {
        $ids[] = syncItemEn($this->a, "Fila {$i}")->id;
    }
    DB::table('sync_demo_items')->update(['updated_at' => $mismoInstante]); // empate total: desempata el id
    sort($ids);

    $h = syncH($this->ana, $this->a);
    $vistos = [];
    $url = '/sync/items?per_page=2';

    do {
        $r = $this->asHeaders($h)->getJson($url)->assertOk();
        $vistos = [...$vistos, ...collect($r->json('data'))->pluck('id')->all()];
        $url = '/sync/items?per_page=2&cursor='.urlencode((string) $r->json('meta.next_cursor'));
    } while ($r->json('meta.has_more'));

    expect($vistos)->toBe($ids);
});

test('un updated_since invalido da 422 y un recurso no declarado 404', function () {
    $h = syncH($this->ana, $this->a);

    $this->asHeaders($h)->getJson('/sync/items?updated_since=ayer')->assertUnprocessable();
    $this->asHeaders($h)->getJson('/sync/nada')->assertNotFound();
});

test('el pull exige el permiso de lectura del recurso', function () {
    $sinLectura = syncUsuario($this->a, ['syncdemo.write']);

    $this->asHeaders(syncH($sinLectura, $this->a))->getJson('/sync/items')->assertForbidden();
});

<?php

use App\Models\File;
use App\Models\Logs\ApiLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

/*
 * Filtros, orden e include estándar (docs/api-routes-conventions.md, «Filtros, orden e include»):
 * lista blanca por endpoint (#[Listing]), 400 `invalid_query`, alias de los parámetros anteriores,
 * paginación por offset y por cursor, y el aislamiento entre tenants intacto.
 */

beforeEach(function () {
    $this->bootIsolation();
    Storage::fake('public');
    Storage::fake('local');
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $this->plataforma = ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];
    $this->hA = $this->isoHeaders($this->userA, $this->a);
});

function archivoDe(object $t, $tenant, User $user, string $name, array $extra = []): File
{
    return $t->ctx->run($tenant, fn () => File::factory()->create(array_merge(['user_id' => $user->id, 'name' => $name], $extra)));
}

test('filtra archivos con filter[campo] y con el alias suelto de antes', function () {
    archivoDe($this, $this->a, $this->userA, 'uno.txt', ['category' => 'docs']);
    archivoDe($this, $this->a, $this->userA, 'dos.txt', ['category' => 'fotos']);

    foreach (['/files?filter[category]=docs', '/files?category=docs'] as $uri) {
        $names = collect($this->isoCall($this->hA, 'GET', $uri)->assertOk()->json('data'))->pluck('name')->all();
        expect($names)->toBe(['uno.txt']);
    }
});

test('ordena archivos con sort=-campo y con sort_by/sort_order', function () {
    archivoDe($this, $this->a, $this->userA, 'a.txt');
    archivoDe($this, $this->a, $this->userA, 'b.txt');

    $desc = collect($this->isoCall($this->hA, 'GET', '/files?sort=-name')->assertOk()->json('data'))->pluck('name')->all();
    $asc = collect($this->isoCall($this->hA, 'GET', '/files?sort_by=name&sort_order=asc')->assertOk()->json('data'))->pluck('name')->all();

    expect($desc)->toBe(['b.txt', 'a.txt'])->and($asc)->toBe(['a.txt', 'b.txt']);
});

test('un filtro no permitido responde 400 invalid_query', function () {
    $this->isoCall($this->hA, 'GET', '/files?filter[path]=x')
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_query');
});

test('ordenar por una columna no permitida responde 400 invalid_query', function () {
    $this->isoCall($this->hA, 'GET', '/files?sort=path')
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_query');
});

test('un include no permitido responde 400 y uno permitido carga la relación', function () {
    archivoDe($this, $this->a, $this->userA, 'x.txt');

    $this->isoCall($this->hA, 'GET', '/files?include=tenant')->assertStatus(400)->assertJsonPath('code', 'invalid_query');
    $this->isoCall($this->hA, 'GET', '/files?include=user')->assertOk()->assertJsonPath('data.0.user.id', $this->userA->id);
});

test('un valor mal formado de un filtro tipado responde 400, no 500', function () {
    $this->isoCall($this->plataforma, 'GET', '/admin/logs/api?filter[status_code]=abc')->assertStatus(400)->assertJsonPath('code', 'invalid_query');
    $this->isoCall($this->hA, 'GET', '/files?filter[is_public]=quizas')->assertStatus(400)->assertJsonPath('code', 'invalid_query');
});

test('la paginación por cursor devuelve next_cursor y la segunda página sin repetir', function () {
    foreach (['a', 'b', 'c'] as $n) {
        archivoDe($this, $this->a, $this->userA, "{$n}.txt");
    }

    $p1 = $this->isoCall($this->hA, 'GET', '/files?sort=name&per_page=2&pagination=cursor')->assertOk();
    expect(collect($p1->json('data'))->pluck('name')->all())->toBe(['a.txt', 'b.txt'])
        ->and($p1->json('meta.has_more'))->toBeTrue();

    $cursor = urlencode($p1->json('meta.next_cursor'));
    $p2 = $this->isoCall($this->hA, 'GET', "/files?sort=name&per_page=2&cursor={$cursor}")->assertOk();

    expect(collect($p2->json('data'))->pluck('name')->all())->toBe(['c.txt']);
});

test('el listado de archivos sigue aislado por tenant con filtros y orden', function () {
    archivoDe($this, $this->a, $this->userA, 'de-a.txt');
    archivoDe($this, $this->b, $this->userB, 'de-b.txt');

    $ok = collect($this->isoCall($this->hA, 'GET', '/files?sort=-name&include=user')->assertOk()->json('data'))->pluck('name')->all();

    expect($ok)->toBe(['de-a.txt']);
});

test('un usuario sin files.manage no ve archivos ajenos aunque pida filter[user_id]', function () {
    $otro = $this->isoUser($this->a);
    archivoDe($this, $this->a, $otro, 'ajeno.txt');
    archivoDe($this, $this->a, $this->userA, 'mio.txt');

    $names = collect($this->isoCall($this->hA, 'GET', "/files?filter[user_id]={$otro->id}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->not->toContain('ajeno.txt');
});

test('los logs de API filtran, ordenan y aceptan los nombres sueltos de antes', function () {
    ApiLog::factory()->create(['response_status' => 200, 'request_method' => 'GET']);
    ApiLog::factory()->create(['response_status' => 500, 'request_method' => 'POST']);

    foreach (['filter[status_code]=500', 'status_code=500', 'filter[method]=post'] as $q) {
        $res = $this->isoCall($this->plataforma, 'GET', "/admin/logs/api?{$q}")->assertOk();
        expect(collect($res->json('data'))->pluck('response_status')->all())->toBe([500]);
    }

    $orden = $this->isoCall($this->plataforma, 'GET', '/admin/logs/api?sort=status_code')->assertOk();
    expect(collect($orden->json('data'))->pluck('response_status')->first())->toBe(200);
    $this->isoCall($this->plataforma, 'GET', '/admin/logs/api?sort=request_body')->assertStatus(400);
});

test('los usuarios admin se filtran por filter[email], se ordenan y rechazan campos ajenos', function () {
    User::factory()->create(['name' => 'Zeta', 'email' => 'zeta@filtros.test']);
    User::factory()->create(['name' => 'Alfa', 'email' => 'alfa@filtros.test']);

    $res = $this->asHeaders($this->plataforma)->getJson('/users?filter[email]=filtros.test&sort=name')->assertOk();
    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['Alfa', 'Zeta']);

    $this->asHeaders($this->plataforma)->getJson('/users?filter[password]=x')->assertStatus(400)->assertJsonPath('code', 'invalid_query');
    $this->asHeaders($this->plataforma)->getJson('/users?sort=password')->assertStatus(400);
    $this->asHeaders($this->plataforma)->getJson('/users?search=Alfa')->assertOk();
});

test('un usuario sin permiso sigue recibiendo 403 en el listado de usuarios', function () {
    $this->asHeaders($this->hA)->getJson('/users?sort=name')->assertForbidden();
});

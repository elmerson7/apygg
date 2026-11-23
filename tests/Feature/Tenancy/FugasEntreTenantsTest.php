<?php

use App\Contracts\AccountDeletionServiceInterface; // module:privacy
use App\Contracts\PersonalDataExportServiceInterface; // module:privacy
use App\Models\AccountDeletionRequest; // module:privacy
use App\Models\InboxNotification;
use App\Models\PersonalDataExport; // module:privacy
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Exceptions\TenancyMisconfigured;
use App\Support\Tenancy\TenancyCoherence;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->bootIsolation();
    $this->ctx->run($this->a, fn () => TenantNote::query()->create(['title' => 'nota-a']));
    $this->ctx->run($this->b, fn () => TenantNote::query()->create(['title' => 'nota-b']));
});

function titulosDe($test, $tenant): array
{
    return $test->ctx->run($tenant, fn () => TenantNote::query()->orderBy('id')->pluck('title')->all());
}

test('un update masivo en el tenant A no toca las filas del tenant B', function () {
    $afectadas = $this->ctx->run($this->a, fn () => TenantNote::query()->update(['title' => 'pisada']));

    expect($afectadas)->toBe(1)
        ->and(titulosDe($this, $this->a))->toBe(['pisada'])
        ->and(titulosDe($this, $this->b))->toBe(['nota-b']);
});

test('un delete masivo en el tenant A no borra las filas del tenant B', function () {
    $this->ctx->run($this->a, fn () => TenantNote::query()->delete());

    expect(titulosDe($this, $this->a))->toBe([])
        ->and(titulosDe($this, $this->b))->toBe(['nota-b']);
});

test('un update masivo no puede mover filas a otro tenant', function () {
    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->update(['tenant_id' => $this->b->id])))
        ->toThrow(CrossTenantViolation::class);

    expect(titulosDe($this, $this->b))->toBe(['nota-b']);
});

test('un insert masivo rellena el tenant activo y no escribe en otro', function () {
    $now = now();
    $this->ctx->run($this->a, fn () => TenantNote::query()->insert([
        ['title' => 'masiva-1', 'created_at' => $now, 'updated_at' => $now],
        ['title' => 'masiva-2', 'created_at' => $now, 'updated_at' => $now],
    ]));

    expect(titulosDe($this, $this->a))->toBe(['nota-a', 'masiva-1', 'masiva-2'])
        ->and(titulosDe($this, $this->b))->toBe(['nota-b']);
});

test('un insert masivo con el tenant_id de otro tenant se rechaza y no escribe nada', function () {
    $now = now();

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->insert([
        ['title' => 'ok', 'created_at' => $now, 'updated_at' => $now],
        ['title' => 'fuga', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now],
    ])))->toThrow(CrossTenantViolation::class);

    expect(titulosDe($this, $this->a))->toBe(['nota-a'])
        ->and(titulosDe($this, $this->b))->toBe(['nota-b']);
});

test('insertGetId e insertOrIgnore rellenan o rechazan el tenant igual que insert', function () {
    $now = now();
    $id = $this->ctx->run($this->a, fn () => TenantNote::query()->insertGetId(['title' => 'una', 'created_at' => $now, 'updated_at' => $now]));

    expect((int) DB::table('tenant_notes')->where('id', $id)->value('tenant_id'))->toBe($this->a->id);

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->insertOrIgnore(
        ['title' => 'fuga', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now],
    )))->toThrow(CrossTenantViolation::class);
});

test('un upsert no puede escribir en otro tenant ni reasignar tenant_id', function () {
    $now = now();

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['id' => 999, 'title' => 'fuga', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now]],
        ['id'], ['title'],
    )))->toThrow(CrossTenantViolation::class);

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['id' => 998, 'title' => 'x', 'created_at' => $now, 'updated_at' => $now]],
        ['id'], ['title', 'tenant_id'],
    )))->toThrow(CrossTenantViolation::class);

    expect(DB::table('tenant_notes')->whereIn('id', [998, 999])->count())->toBe(0);
});

test('un upsert sin tenant_id en uniqueBy se rechaza aunque no traiga columnas de tenant', function () {
    $now = now();
    $ajena = $this->ctx->run($this->b, fn () => TenantNote::query()->create(['title' => 'de B']));

    expect(fn () => $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['id' => $ajena->id, 'title' => 'pisada', 'created_at' => $now, 'updated_at' => $now]],
        ['id'],
    )))->toThrow(CrossTenantViolation::class);

    expect(DB::table('tenant_notes')->where('id', $ajena->id)->value('title'))->toBe('de B');
});

test('un upsert con tenant_id en uniqueBy y sin columnas a actualizar no toca tenant_id ni filas de otro tenant', function () {
    DB::statement('create unique index tenant_notes_tenant_title on tenant_notes (tenant_id, title)');
    $now = now();
    $this->ctx->run($this->b, fn () => TenantNote::query()->create(['title' => 'compartido']));

    $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['title' => 'compartido', 'created_at' => $now, 'updated_at' => $now]],
        ['tenant_id', 'title'],
    ));
    $this->ctx->run($this->a, fn () => TenantNote::query()->upsert(
        [['title' => 'compartido', 'created_at' => $now, 'updated_at' => $now->copy()->addMinute()]],
        ['tenant_id', 'title'],
    ));

    $filas = DB::table('tenant_notes')->where('title', 'compartido')->pluck('tenant_id')->sort()->values()->all();

    expect($filas)->toBe(collect([$this->a->id, $this->b->id])->sort()->values()->all());
});

test('un insert masivo sin tenant en el contexto falla cerrado', function () {
    $now = now();

    expect(fn () => TenantNote::query()->insert([['title' => 'huerfana', 'created_at' => $now, 'updated_at' => $now]]))
        ->toThrow(MissingTenantContext::class);

    expect(DB::table('tenant_notes')->where('title', 'huerfana')->count())->toBe(0);
});

test('en modo plataforma un insert masivo exige tenant_id explícito en cada fila', function () {
    $now = now();

    expect(fn () => TenantQuery::withoutTenant(fn () => TenantNote::query()->insert([['title' => 'sin-dueno', 'created_at' => $now, 'updated_at' => $now]])))
        ->toThrow(MissingTenantContext::class);

    TenantQuery::withoutTenant(fn () => TenantNote::query()->insert([['title' => 'con-dueno', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now]]));

    expect(titulosDe($this, $this->b))->toBe(['nota-b', 'con-dueno']);
});

test('con multiempresa apagado los insert masivos no se tocan', function () {
    config(['multi-tenancy.enabled' => false]);
    $now = now();

    TenantNote::query()->insert([['title' => 'libre', 'tenant_id' => $this->b->id, 'created_at' => $now, 'updated_at' => $now]]);

    expect(DB::table('tenant_notes')->where('title', 'libre')->value('tenant_id'))->toBe($this->b->id);
});

// module:privacy:start
test('la exportación de datos de un usuario no devuelve filas de otras personas del mismo ni de otro tenant', function () {
    Storage::fake('local');
    Mail::fake();
    config(['queue.default' => 'sync', 'privacy.export.disk' => 'local']);
    $user = $this->isoUser($this->a);
    $otro = $this->isoUser($this->b);
    $now = now();
    foreach ([[$this->a, $user, 'mia'], [$this->b, $otro, 'ajena']] as [$tenant, $owner, $title]) {
        DB::table('inbox_notifications')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'type' => 'x', 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);
    }

    $export = PersonalDataExport::query()->findOrFail(app(PersonalDataExportServiceInterface::class)->request($user)->id);
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, Storage::disk('local')->get($export->path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $notifs = json_decode((string) $zip->getFromName('notifications.json'), true);
    $zip->close();
    unlink($tmp);

    expect(array_column($notifs['inbox'], 'title'))->toBe(['mia']);
});

test('el borrado de cuenta de un usuario del tenant A no borra datos del usuario del tenant B', function () {
    Mail::fake();
    $user = $this->isoUser($this->a);
    $otro = $this->isoUser($this->b);
    $now = now();
    foreach ([[$this->a, $user, 'mia'], [$this->b, $otro, 'ajena']] as [$tenant, $owner, $title]) {
        DB::table('inbox_notifications')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'type' => 'x', 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('api_keys')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => $title, 'key' => hash('sha256', $title), 'created_at' => $now, 'updated_at' => $now]);
    }

    $svc = app(AccountDeletionServiceInterface::class);
    $svc->request($user, 'password', null, null);
    $this->travel(15)->days();
    $svc->execute(AccountDeletionRequest::query()->firstOrFail()->id);

    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->pluck('title')->all())->toBe(['ajena']));
    expect(DB::table('api_keys')->pluck('name')->all())->toBe(['ajena']);
});
// module:privacy:end

test('con multiempresa apagado y varias empresas la comprobación de coherencia falla en voz alta', function () {
    config(['multi-tenancy.enabled' => false, 'multi-tenancy.strict' => true]);
    Cache::forget('tenancy:coherence-problems');

    expect(TenancyCoherence::problems())->toHaveCount(1)
        ->and(fn () => TenancyCoherence::assertCoherent())->toThrow(TenancyMisconfigured::class)
        ->and(Artisan::call('tenancy:check'))->toBe(1);
});

test('con multiempresa apagado y una sola empresa la coherencia es válida y con él encendido no hay problemas', function () {
    config(['multi-tenancy.enabled' => false, 'multi-tenancy.strict' => true]);
    $this->b->delete();
    Cache::forget('tenancy:coherence-problems');

    expect(TenancyCoherence::problems())->toBe([])
        ->and(Artisan::call('tenancy:check'))->toBe(0);

    config(['multi-tenancy.enabled' => true]);
    $this->makeTenant('gamma');

    expect(TenancyCoherence::problems())->toBe([]);
});

test('el middleware tenant devuelve 500 con multiempresa apagado y varias empresas en modo estricto', function () {
    config(['multi-tenancy.enabled' => false, 'multi-tenancy.strict' => true]);
    Cache::forget('tenancy:coherence-problems');
    $user = $this->isoUser($this->a);

    $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
        ->getJson('/webhooks')->assertStatus(500);
});

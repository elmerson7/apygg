<?php

use App\Enums\TenantStatus;
use App\Events\UserUpdated;
use App\Jobs\SendWebhookJob;
use App\Listeners\WebhookListener;
use App\Models\ApiKey;
use App\Models\File;
use App\Models\Logs\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyService;
use App\Services\FileService;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');
    Storage::fake('local');
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);

    // Una persona que es miembro de A y de B (el caso que más fácilmente mezcla datos)
    $this->user = User::factory()->create();
    $this->user->assignRole(Role::where('name', 'user')->first());
    $this->user->givePermissionTo('api-keys.manage');
    $this->addMember($this->a, $this->user);
    $this->addMember($this->b, $this->user);

    Route::middleware(['api', 'auth:api-key'])->get('/_tenancy/key-context', fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
        'webhooks' => Webhook::query()->pluck('name')->all(),
    ]));
});

function rtJwt(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function rtHeaders(User $user, $tenant): array
{
    return rtJwt($user) + ['X-Tenant' => (string) $tenant->id];
}

function rtArchivo(string $nombre = 'a.txt'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, 'contenido de prueba');

    return new UploadedFile($ruta, $nombre, null, null, true);
}

function rtEn($test, $tenant, Closure $fn): mixed
{
    return $test->ctx->run($tenant, $fn);
}

// ── Archivos ───────────────────────────────────────────────────────────────

test('un archivo subido desde A queda en A con la clave de almacenamiento del tenant', function () {
    config(['files.prefix' => '']); // el prefijo de entorno se prueba en SubidaDirectaYCuotasTest
    $res = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->postJson('/files', ['file' => rtArchivo(), 'is_public' => false])
        ->assertCreated();

    $file = TenantQuery::withoutTenant(fn () => File::findOrFail($res->json('data.id')), 'test');

    expect($file->tenant_id)->toBe($this->a->id)
        ->and($file->path)->toStartWith('tenants/'.$this->a->id.'/');
    Storage::disk('local')->assertExists($file->path);
});

test('el listado de archivos de A no incluye los de B', function () {
    rtEn($this, $this->a, fn () => File::factory()->create(['user_id' => $this->user->id, 'name' => 'de-a.txt']));
    rtEn($this, $this->b, fn () => File::factory()->create(['user_id' => $this->user->id, 'name' => 'de-b.txt']));

    $names = collect($this->withHeaders(rtHeaders($this->user, $this->a))->getJson('/files')->assertOk()->json('data'))
        ->pluck('name')->all();

    expect($names)->toBe(['de-a.txt']);
});

test('ver, editar, borrar y descargar un archivo de B desde A responde 404', function () {
    $fileB = rtEn($this, $this->b, fn () => File::factory()->create(['user_id' => $this->user->id]));
    $h = rtHeaders($this->user, $this->a);

    $this->withHeaders($h)->getJson("/files/{$fileB->id}")->assertNotFound();
    $this->withHeaders($h)->putJson("/files/{$fileB->id}", ['description' => 'x'])->assertNotFound();
    $this->withHeaders($h)->deleteJson("/files/{$fileB->id}")->assertNotFound();
    $this->withHeaders($h)->getJson("/files/{$fileB->id}/download")->assertNotFound();
    $this->withHeaders($h)->getJson("/files/{$fileB->id}/temporary-url")->assertNotFound();

    expect(TenantQuery::withoutTenant(fn () => File::find($fileB->id), 'test'))->not->toBeNull();
});

test('las rutas de archivos exigen tenant', function () {
    $this->withHeaders(rtJwt($this->user))->getJson('/files')->assertStatus(400);
});

test('la URL firmada de un archivo de A descarga sin sesión y fija el tenant desde la firma', function () {
    $res = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->postJson('/files', ['file' => rtArchivo(), 'is_public' => false])->assertCreated();
    $url = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->getJson('/files/'.$res->json('data.id').'/temporary-url')->assertOk()->json('data.url');

    expect($url)->toContain('tenant='.$this->a->id);
    $this->get($url)->assertOk();
    expect($this->ctx->id())->toBeNull();
});

test('la URL firmada de A no sirve en el contexto de B', function () {
    $res = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->postJson('/files', ['file' => rtArchivo(), 'is_public' => false])->assertCreated();
    $url = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->getJson('/files/'.$res->json('data.id').'/temporary-url')->json('data.url');

    $this->get($url, ['X-Tenant' => (string) $this->b->id])->assertForbidden();
    $this->get($url, ['X-Tenant' => 'beta'])->assertForbidden();
});

test('una URL firmada con el tenant alterado se rechaza por la firma', function () {
    $fileA = rtEn($this, $this->a, fn () => File::factory()->create(['user_id' => $this->user->id]));
    $url = rtEn($this, $this->a, fn () => app(FileService::class)->temporaryDownloadUrl($fileA));

    $this->get(str_replace('tenant='.$this->a->id, 'tenant='.$this->b->id, $url))->assertForbidden();
});

test('una URL firmada con el tenant A para un archivo de B no lo encuentra', function () {
    $fileB = rtEn($this, $this->b, fn () => File::factory()->create(['user_id' => $this->user->id, 'disk' => 'local', 'path' => 'x/y.txt']));
    Storage::disk('local')->put('x/y.txt', 'secreto de B');

    $url = URL::temporarySignedRoute('files.signed', now()->addMinutes(5), ['id' => $fileB->id, 'tenant' => $this->a->id]);

    $this->get($url)->assertNotFound();
});

test('una URL firmada sin tenant con multiempresa activa se rechaza', function () {
    $fileA = rtEn($this, $this->a, fn () => File::factory()->create(['user_id' => $this->user->id]));
    $url = URL::temporarySignedRoute('files.signed', now()->addMinutes(5), ['id' => $fileA->id]);

    $this->get($url)->assertForbidden();
});

test('un archivo cuya ruta apunta a otro tenant no se sirve', function () {
    $fileA = rtEn($this, $this->a, fn () => File::factory()->create([
        'user_id' => $this->user->id, 'disk' => 'local', 'path' => 'tenants/'.$this->b->id.'/robado.txt',
    ]));
    Storage::disk('local')->put($fileA->path, 'contenido de B');

    $this->withHeaders(rtHeaders($this->user, $this->a))->getJson("/files/{$fileA->id}/download")->assertNotFound();
});

test('con multiempresa apagada la clave de almacenamiento no lleva tenant', function () {
    config(['multi-tenancy.enabled' => false, 'files.prefix' => '']);

    expect(app(FileService::class)->tenantStoragePath('uploads'))->toBe('uploads');
});

// ── Webhooks ───────────────────────────────────────────────────────────────

function rtWebhook($test, $tenant, string $name): Webhook
{
    return $test->ctx->run($tenant, fn () => Webhook::factory()->create([
        'user_id' => $test->user->id, 'name' => $name, 'status' => 'active', 'events' => null,
        'url' => 'https://example.com/hook', 'secret' => str_repeat('s', 40),
    ]));
}

test('un webhook creado desde A queda en A', function () {
    $res = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->postJson('/webhooks', ['name' => 'mio', 'url' => 'https://example.com/h', 'events' => ['user.updated']])
        ->assertCreated();

    $webhook = TenantQuery::withoutTenant(fn () => Webhook::findOrFail($res->json('data.id')), 'test');

    expect($webhook->tenant_id)->toBe($this->a->id);
});

test('listar, ver, editar, borrar, rotar y ver entregas de un webhook de B desde A no es posible', function () {
    $webhookB = rtWebhook($this, $this->b, 'de-b');
    $deliveryB = rtEn($this, $this->b, fn () => WebhookDelivery::create([
        'webhook_id' => $webhookB->id, 'event_type' => 'user.updated', 'payload' => [], 'status' => WebhookDelivery::STATUS_FAILED,
    ]));
    rtWebhook($this, $this->a, 'de-a');
    $h = rtHeaders($this->user, $this->a);

    $names = collect($this->withHeaders($h)->getJson('/webhooks')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['de-a']);

    $this->withHeaders($h)->getJson("/webhooks/{$webhookB->id}")->assertNotFound();
    $this->withHeaders($h)->putJson("/webhooks/{$webhookB->id}", ['name' => 'hack'])->assertNotFound();
    $this->withHeaders($h)->deleteJson("/webhooks/{$webhookB->id}")->assertNotFound();
    $this->withHeaders($h)->postJson("/webhooks/{$webhookB->id}/rotate-secret")->assertNotFound();
    $this->withHeaders($h)->getJson("/webhooks/{$webhookB->id}/deliveries")->assertNotFound();
    $this->withHeaders($h)->postJson("/webhooks/{$webhookB->id}/deliveries/{$deliveryB->id}/retry")->assertNotFound();
});

test('un evento ocurrido en A solo se entrega a los webhooks de A', function () {
    Queue::fake();
    $webhookA = rtWebhook($this, $this->a, 'de-a');
    $webhookB = rtWebhook($this, $this->b, 'de-b');

    rtEn($this, $this->a, fn () => app(WebhookListener::class)->handle(new UserUpdated($this->user)));

    $deliveries = TenantQuery::withoutTenant(fn () => WebhookDelivery::query()->get(), 'test');

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->webhook_id)->toBe($webhookA->id)
        ->and($deliveries->first()->tenant_id)->toBe($this->a->id)
        ->and($deliveries->pluck('webhook_id')->all())->not->toContain($webhookB->id);
    Queue::assertPushed(SendWebhookJob::class, 1);
});

test('un evento sin tenant no se entrega a ningún webhook', function () {
    Queue::fake();
    rtWebhook($this, $this->a, 'de-a');
    rtWebhook($this, $this->b, 'de-b');

    app(WebhookListener::class)->handle(new UserUpdated($this->user));

    expect(TenantQuery::withoutTenant(fn () => WebhookDelivery::query()->count(), 'test'))->toBe(0);
    Queue::assertNothingPushed();
});

test('el job de webhooks lleva el tenant con el que se encoló y lo restaura', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = rtWebhook($this, $this->a, 'de-a');
    $job = rtEn($this, $this->a, function () use ($webhook) {
        $delivery = WebhookDelivery::create(['webhook_id' => $webhook->id, 'event_type' => 'user.updated', 'payload' => [], 'status' => 'pending']);

        return [new SendWebhookJob($webhook->id, $delivery->id), $delivery];
    });

    // En el worker no hay contexto: el job lo restaura desde su propio tenant
    $this->ctx->clear();
    $job[0]->handle();

    expect(TenantQuery::withoutTenant(fn () => $job[1]->fresh()->status, 'test'))->toBe(WebhookDelivery::STATUS_SUCCESS);
});

test('el job de webhooks de A no se ejecuta en el contexto de B', function () {
    $webhook = rtWebhook($this, $this->a, 'de-a');
    $job = rtEn($this, $this->a, fn () => new SendWebhookJob($webhook->id, 1));

    rtEn($this, $this->b, fn () => $job->handle());
})->throws(CrossTenantViolation::class);

test('un job de webhooks encolado sin tenant falla cerrado', function () {
    (new SendWebhookJob(1, 1))->handle();
})->throws(MissingTenantContext::class);

test('la limpieza de secrets antiguos recorre todos los tenants', function () {
    foreach ([$this->a, $this->b] as $tenant) {
        $w = rtWebhook($this, $tenant, 'w-'.$tenant->slug);
        rtEn($this, $tenant, fn () => $w->update(['old_secret' => 'viejo', 'secret_rotated_at' => now()->subDays(30)]));
    }

    $this->artisan('webhooks:cleanup-old-secrets')->assertSuccessful();

    expect(TenantQuery::withoutTenant(fn () => Webhook::query()->whereNotNull('old_secret')->count(), 'test'))->toBe(0);
});

// ── API keys ───────────────────────────────────────────────────────────────

function rtKey($test, $tenant, array $scopes = ['profile.read'], string $name = 'k'): array
{
    return $test->ctx->run($tenant, function () use ($test, $scopes, $name) {
        $r = app(ApiKeyService::class)->create($test->user, $name, $scopes);

        return [$r['key'], $r['api_key']];
    });
}

test('una API key creada desde A queda en A', function () {
    $res = $this->withHeaders(rtHeaders($this->user, $this->a))
        ->postJson('/api-keys', ['name' => 'key-a', 'scopes' => ['profile.read']])->assertCreated();

    expect(TenantQuery::withoutTenant(fn () => ApiKey::findOrFail($res->json('data.api_key.id'))->tenant_id, 'test'))->toBe($this->a->id);
});

test('listar, ver, rotar y revocar una API key de B desde A no es posible', function () {
    [, $keyB] = rtKey($this, $this->b, ['profile.read'], 'de-b');
    rtKey($this, $this->a, ['profile.read'], 'de-a');
    $h = rtHeaders($this->user, $this->a);

    $names = collect($this->withHeaders($h)->getJson('/api-keys')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['de-a']);

    $this->withHeaders($h)->getJson("/api-keys/{$keyB->id}")->assertNotFound();
    $this->withHeaders($h)->postJson("/api-keys/{$keyB->id}/rotate", [])->assertNotFound();
    $this->withHeaders($h)->deleteJson("/api-keys/{$keyB->id}")->assertNotFound();
});

test('una API key fija el tenant al autenticarse y solo ve los datos de su tenant', function () {
    rtWebhook($this, $this->a, 'de-a');
    rtWebhook($this, $this->b, 'de-b');
    [$keyA] = rtKey($this, $this->a);

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])
        ->assertOk()
        ->assertJson(['tenant' => $this->a->id, 'webhooks' => ['de-a']]);
});

test('la cabecera X-Tenant no cambia el tenant de una API key', function () {
    rtWebhook($this, $this->b, 'de-b');
    [$keyA] = rtKey($this, $this->a);

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA, 'X-Tenant' => (string) $this->b->id])
        ->assertOk()
        ->assertJson(['tenant' => $this->a->id, 'webhooks' => []]);
});

test('una API key sin tenant no autentica con multiempresa activa', function () {
    $plain = 'apygg_live_'.str_repeat('z', 40);
    DB::table('api_keys')->insert([
        'user_id' => $this->user->id, 'name' => 'huerfana', 'key' => hash('sha256', $plain),
        'scopes' => json_encode(['*']), 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $plain])->assertUnauthorized();
});

test('una API key de un tenant suspendido no autentica', function () {
    [$keyA] = rtKey($this, $this->a);
    $this->a->update(['status' => TenantStatus::Suspended]);
    Auth::forgetGuards();

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])->assertUnauthorized();
});

test('una API key de un usuario que ya no es miembro del tenant no autentica', function () {
    [$keyA] = rtKey($this, $this->a);
    $this->a->users()->updateExistingPivot($this->user->id, ['status' => 'suspended']);

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])->assertUnauthorized();
});

test('revocar una API key desde su tenant la invalida aunque estuviera en caché', function () {
    [$keyA, $modelA] = rtKey($this, $this->a);

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])->assertOk();
    $this->withHeaders(rtHeaders($this->user, $this->a))->deleteJson("/api-keys/{$modelA->id}")->assertOk();
    Auth::forgetGuards();

    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])->assertUnauthorized();
});

test('el contexto de la API key se limpia al terminar la petición', function () {
    [$keyA] = rtKey($this, $this->a);
    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $keyA])->assertOk();

    expect(app(TenantContext::class)->id())->toBeNull();
});

test('el comando api-key:create exige tenant con multiempresa activa', function () {
    $this->artisan('api-key:create', ['userId' => $this->user->id, 'name' => 'cli'])->assertFailed();
    $this->artisan('api-key:create', ['userId' => $this->user->id, 'name' => 'cli', '--tenant' => 'acme', '--scopes' => 'profile.read'])->assertSuccessful();

    expect(TenantQuery::withoutTenant(fn () => ApiKey::query()->where('name', 'cli')->value('tenant_id'), 'test'))->toBe($this->a->id);
});

// ── Logs: registran el tenant pero no se ocultan ────────────────────────────

test('los logs registran el tenant activo y la plataforma los ve todos', function () {
    $logA = rtEn($this, $this->a, fn () => ActivityLog::create(['user_id' => $this->user->id, 'model_type' => 'x', 'model_id' => 1, 'action' => 'created']));
    $logSin = ActivityLog::create(['user_id' => $this->user->id, 'model_type' => 'x', 'model_id' => 2, 'action' => 'created']);
    rtEn($this, $this->b, fn () => ActivityLog::create(['user_id' => $this->user->id, 'model_type' => 'x', 'model_id' => 3, 'action' => 'created']));

    expect($logA->tenant_id)->toBe($this->a->id)
        ->and($logSin->tenant_id)->toBeNull()
        ->and(ActivityLog::query()->where('model_type', 'x')->count())->toBe(3)
        ->and(ActivityLog::query()->where('model_type', 'x')->where('tenant_id', $this->a->id)->count())->toBe(1);
});

// ── Con multiempresa apagada nada cambia ────────────────────────────────────

test('con multiempresa apagada los recursos funcionan sin tenant ni cabecera', function () {
    config(['multi-tenancy.enabled' => false]);

    $this->withHeaders(rtJwt($this->user))->postJson('/files', ['file' => rtArchivo(), 'is_public' => false])->assertCreated();
    $this->withHeaders(rtJwt($this->user))->getJson('/files')->assertOk();
    $this->withHeaders(rtJwt($this->user))->postJson('/webhooks', ['name' => 'webhook', 'url' => 'https://example.com/h', 'events' => ['user.updated']])->assertCreated();
    $this->withHeaders(rtJwt($this->user))->getJson('/api-keys')->assertOk();
    [$key] = (function () {
        $r = app(ApiKeyService::class)->create($this->user, 'k', ['profile.read']);

        return [$r['key']];
    })();
    Auth::forgetGuards();
    $this->getJson('/_tenancy/key-context', ['X-API-Key' => $key])->assertOk()->assertJson(['tenant' => null]);
});

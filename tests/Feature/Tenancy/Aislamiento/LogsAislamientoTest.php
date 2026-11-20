<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
| Los logs NO se aíslan (decisión documentada en docs/multi-tenancy.md): registran el tenant y la plataforma
| los ve todos, filtrando con ?tenant_id=. Lo que se prueba es que se etiquetan bien y que un usuario de
| tenant (sin system.logs) no los lee.
*/

beforeEach(function () {
    $this->bootIsolation();
    $this->userA = $this->isoUser($this->a);
    $this->plataforma = ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];
});

test('cada log registra el tenant activo y el filtro tenant_id de la plataforma no mezcla', function () {
    $this->ctx->run($this->a, fn () => ActivityLog::create(['user_id' => $this->userA->id, 'model_type' => 'iso', 'model_id' => 1, 'action' => 'created']));
    $this->ctx->run($this->b, fn () => ActivityLog::create(['user_id' => $this->userA->id, 'model_type' => 'iso', 'model_id' => 2, 'action' => 'created']));

    expect(ActivityLog::query()->where('model_type', 'iso')->where('model_id', 1)->value('tenant_id'))->toBe($this->a->id)
        ->and(ActivityLog::query()->where('model_type', 'iso')->where('model_id', 2)->value('tenant_id'))->toBe($this->b->id);

    $res = $this->isoCall($this->plataforma, 'GET', "/admin/logs/activity?tenant_id={$this->a->id}")->assertOk();
    $ids = collect($res->json('data'))->where('model_type', 'iso')->pluck('model_id')->all();

    expect($ids)->toBe([1]);
});

test('los logs de API y seguridad también llevan el tenant', function () {
    $apiLog = $this->ctx->run($this->a, fn () => ApiLog::factory()->create());
    $secLog = $this->ctx->run($this->b, fn () => SecurityLog::factory()->create());

    expect($apiLog->tenant_id)->toBe($this->a->id)
        ->and($secLog->tenant_id)->toBe($this->b->id);

    $this->isoCall($this->plataforma, 'GET', "/admin/logs/api?tenant_id={$this->b->id}")->assertOk()->assertJsonMissing(['id' => $apiLog->id]);
    $this->isoCall($this->plataforma, 'GET', "/admin/logs/security?tenant_id={$this->a->id}")->assertOk()->assertJsonMissing(['id' => $secLog->id]);
});

test('un usuario de tenant sin permiso de plataforma no lista ni borra logs', function () {
    $h = $this->isoHeaders($this->userA, $this->a);

    foreach (['activity', 'api', 'security'] as $tipo) {
        $this->isoCall($h, 'GET', "/admin/logs/{$tipo}")->assertForbidden();
        $this->isoCall($h, 'DELETE', "/admin/logs/{$tipo}")->assertForbidden();
    }
});

test('un log creado sin tenant queda con tenant_id nulo y nunca falla cerrado', function () {
    $this->ctx->clear();

    $log = ActivityLog::create(['user_id' => $this->userA->id, 'model_type' => 'iso', 'model_id' => 9, 'action' => 'created']);

    expect($log->tenant_id)->toBeNull();
});

<?php

use App\Contracts\NotificationServiceInterface;
use App\Models\InboxNotification;
use App\Models\NotificationDelivery;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    config(['queue.default' => 'sync']);
    // La MISMA persona en A y en B: la bandeja es por usuario Y tenant.
    $this->user = $this->isoUser($this->a);
    $this->b->users()->attach($this->user->getKey(), ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);
    $this->other = $this->isoUser($this->b);
    $notify = app(NotificationServiceInterface::class);
    $notify->notifyNow($this->user, 'system_announcement', ['title' => 'de-a', 'message' => 'm'], $this->a);
    $notify->notifyNow($this->user, 'system_announcement', ['title' => 'de-b', 'message' => 'm'], $this->b);
    $notify->notifyNow($this->other, 'system_announcement', ['title' => 'otro-usuario-b', 'message' => 'm'], $this->b);
});

test('el listado de la bandeja desde A solo muestra lo de A y lo de B desde B', function () {
    $this->assertListIsolated($this->isoHeaders($this->user, $this->a), '/user/notifications', 'title', ['de-a'], ['de-b', 'otro-usuario-b']);
    $this->assertListIsolated($this->isoHeaders($this->user, $this->b), '/user/notifications', 'title', ['de-b'], ['de-a', 'otro-usuario-b']);
});

test('leer o borrar un aviso de B (o de otro usuario) desde A responde 404 y no lo toca', function () {
    $ids = $this->ctx->run($this->b, fn () => InboxNotification::query()->pluck('id', 'title'));

    $this->assertForeignIdNotFound($this->isoHeaders($this->user, $this->a), [
        ['POST', "/user/notifications/{$ids['de-b']}/read"],
        ['DELETE', "/user/notifications/{$ids['de-b']}"],
        ['POST', "/user/notifications/{$ids['otro-usuario-b']}/read"],
    ]);

    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->whereNull('read_at')->count())->toBe(2));
});

test('marcar todas como leídas desde A no toca las de B', function () {
    $this->isoCall($this->isoHeaders($this->user, $this->a), 'POST', '/user/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);

    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->whereNull('read_at')->count())->toBe(2));
});

test('el contador de no leídas es del tenant activo', function () {
    $this->isoCall($this->isoHeaders($this->user, $this->a), 'GET', '/user/notifications/unread-count')->assertJsonPath('data.unread', 1);
});

test('crear la bandeja desde A con un tenant_id de B en los parámetros queda en A', function () {
    app(NotificationServiceInterface::class)->notifyNow($this->user, 'system_announcement', ['title' => 'x', 'message' => 'm', 'tenant_id' => $this->b->id], $this->a);

    $this->ctx->run($this->a, fn () => expect(InboxNotification::query()->where('title', 'x')->exists())->toBeTrue());
    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->where('title', 'x')->exists())->toBeFalse());
});

test('el modelo solo ve las filas del tenant activo y falla cerrado sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(InboxNotification::query()->pluck('title')->all())->toBe(['de-a']));
    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->pluck('title')->all())->toEqualCanonicalizing(['de-b', 'otro-usuario-b']));
    $this->ctx->clear();
    expect(fn () => InboxNotification::query()->count())->toThrow(MissingTenantContext::class);
});

test('las entregas registran el tenant con el que se enviaron', function () {
    $porTenant = NotificationDelivery::query()->where('channel', 'database')->pluck('tenant_id')->all();

    expect($porTenant)->toEqualCanonicalizing([$this->a->id, $this->b->id, $this->b->id]);
});

test('sin tenant en el contexto la bandeja se omite en vez de fallar o mezclar', function () {
    $this->ctx->clear();
    app(NotificationServiceInterface::class)->notifyNow($this->user, 'system_announcement', ['title' => 'sin', 'message' => 'm']);

    expect(NotificationDelivery::query()->where('channel', 'database')->latest('id')->value('skipped_reason'))->toBe('no_tenant_context');
    $this->ctx->run($this->a, fn () => expect(InboxNotification::query()->where('title', 'sin')->exists())->toBeFalse());
});

test('los dispositivos son de la persona: valen en cualquier tenant y no se ven entre usuarios', function () {
    $hA = $this->isoHeaders($this->user, $this->a);
    $this->isoCall($hA, 'POST', '/user/devices', ['token' => 'tok-persona-1', 'platform' => 'ios'])->assertCreated();

    $this->isoCall($this->isoHeaders($this->user, $this->b), 'GET', '/user/devices')->assertOk()->assertJsonCount(1, 'data');
    $this->isoCall($this->isoHeaders($this->other, $this->b), 'GET', '/user/devices')->assertOk()->assertJsonCount(0, 'data');
});

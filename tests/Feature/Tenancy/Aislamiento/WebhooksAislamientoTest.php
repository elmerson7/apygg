<?php

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    $this->userA = $this->isoUser($this->a);
    $this->userB = $this->isoUser($this->b);
    $hacer = fn ($tenant, $user, string $name) => $this->ctx->run($tenant, function () use ($user, $name) {
        $w = Webhook::factory()->create(['user_id' => $user->id, 'name' => $name, 'status' => 'active', 'events' => null, 'url' => 'https://example.com/h', 'secret' => str_repeat('s', 40)]);
        $d = WebhookDelivery::create(['webhook_id' => $w->id, 'event_type' => 'user.updated', 'payload' => [], 'status' => WebhookDelivery::STATUS_FAILED]);

        return [$w, $d];
    });
    [$this->webhookA, $this->deliveryA] = $hacer($this->a, $this->userA, 'de-a');
    [$this->webhookB, $this->deliveryB] = $hacer($this->b, $this->userB, 'de-b');
});

test('el listado de webhooks desde A no muestra los de B', function () {
    $this->assertListIsolated($this->isoHeaders($this->userA, $this->a), '/webhooks', 'name', ['de-a'], ['de-b']);
});

test('ver, editar, borrar, rotar y consultar entregas de un webhook de B desde A responde 404', function () {
    $w = $this->webhookB->id;
    $this->assertForeignIdNotFound($this->isoHeaders($this->userA, $this->a), [
        ['GET', "/webhooks/{$w}"],
        ['PUT', "/webhooks/{$w}", ['name' => 'hack']],
        ['DELETE', "/webhooks/{$w}"],
        ['POST', "/webhooks/{$w}/rotate-secret"],
        ['GET', "/webhooks/{$w}/deliveries"],
        ['POST', "/webhooks/{$w}/deliveries/{$this->deliveryB->id}/retry"],
        // Un webhook propio con la entrega de B tampoco la encuentra
        ['POST', "/webhooks/{$this->webhookA->id}/deliveries/{$this->deliveryB->id}/retry"],
    ]);

    expect(TenantQuery::withoutTenant(fn () => Webhook::find($w)->name, 'test'))->toBe('de-b');
});

test('crear un webhook desde A ignora un tenant_id de B en el cuerpo', function () {
    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->userA, $this->a),
        '/webhooks',
        ['name' => 'nuevo', 'url' => 'https://example.com/h', 'events' => ['user.updated']],
        fn ($res) => TenantQuery::withoutTenant(fn () => Webhook::findOrFail($res->json('data.id'))->tenant_id, 'test'),
    );
});

test('las entregas se aíslan por tenant y fallan cerradas sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(WebhookDelivery::query()->pluck('id')->all())->toBe([$this->deliveryA->id]));
    $this->ctx->run($this->b, fn () => expect(WebhookDelivery::query()->pluck('id')->all())->toBe([$this->deliveryB->id]));
    $this->ctx->clear();
    expect(fn () => WebhookDelivery::query()->count())->toThrow(MissingTenantContext::class);
});

test('el modelo Webhook solo ve las filas del tenant activo y falla cerrado sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(Webhook::query()->pluck('name')->all())->toBe(['de-a']));
    $this->ctx->run($this->b, fn () => expect(Webhook::query()->pluck('name')->all())->toBe(['de-b']));
    $this->ctx->clear();
    expect(fn () => Webhook::query()->count())->toThrow(MissingTenantContext::class);
});

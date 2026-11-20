<?php

use App\Jobs\ProcessInboundWebhookJob;
use App\Models\InboundWebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\FixedTenantResolver;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\RecordingInboundHandler;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->bootIsolation();
    $this->handler = new RecordingInboundHandler;
    app()->instance(RecordingInboundHandler::class, $this->handler);

    config([
        'services.acme.webhook_secret' => 'sec',
        'integrations.inbound_webhooks.providers.acme' => [
            'secret_config' => 'services.acme.webhook_secret',
            'signature' => ['driver' => 'hmac', 'header' => 'X-Sig'],
            'delivery_id' => ['payload' => 'id'],
            'handler' => RecordingInboundHandler::class,
            'tenant_resolver' => FixedTenantResolver::class,
        ],
    ]);
});

function whTenant($test, array $payload, string $provider = 'acme')
{
    $body = json_encode($payload);

    return $test->call('POST', "/webhooks/inbound/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIG' => hash_hmac('sha256', $body, 'sec'),
    ], $body);
}

test('el recibo guarda el tenant resuelto del payload firmado y el handler corre en ESE tenant', function () {
    whTenant($this, ['id' => 'e1', 'tenant' => $this->a->id])->assertOk();
    whTenant($this, ['id' => 'e2', 'tenant' => $this->b->id])->assertOk();

    expect(InboundWebhookReceipt::where('delivery_id', 'e1')->value('tenant_id'))->toBe($this->a->id)
        ->and(InboundWebhookReceipt::where('delivery_id', 'e2')->value('tenant_id'))->toBe($this->b->id)
        ->and(array_column($this->handler->handled, 'tenant'))->toBe([$this->a->id, $this->b->id]);
});

test('el job restaura el contexto del tenant del recibo aunque el worker no tenga ninguno y lo deja limpio', function () {
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'e3', 'tenant_id' => $this->b->id, 'payload' => ['id' => 'e3']]);
    $this->ctx->clear();

    app()->call([new ProcessInboundWebhookJob($receipt->id), 'process']);

    expect($this->handler->handled[0]['tenant'])->toBe($this->b->id)
        ->and($this->ctx->id())->toBeNull();
});

test('el mismo delivery_id en tenants distintos es el mismo recibo del proveedor: no se duplica ni se cruza', function () {
    whTenant($this, ['id' => 'dup', 'tenant' => $this->a->id])->assertOk();
    whTenant($this, ['id' => 'dup', 'tenant' => $this->b->id])->assertOk()->assertJsonPath('data.status', 'duplicate');

    expect(InboundWebhookReceipt::count())->toBe(1)
        ->and(InboundWebhookReceipt::first()->tenant_id)->toBe($this->a->id);
});

test('un tenant inexistente en el payload se rechaza con 422 tenant_invalid y no guarda nada', function () {
    whTenant($this, ['id' => 'x', 'tenant' => 999999])->assertStatus(422)->assertJsonPath('code', 'tenant_invalid');

    expect(InboundWebhookReceipt::count())->toBe(0);
});

test('un webhook sin tenant resoluble se acepta como de plataforma, o se rechaza si el proveedor lo exige', function () {
    whTenant($this, ['id' => 'p1'])->assertOk();
    expect(InboundWebhookReceipt::first()->tenant_id)->toBeNull();

    config(['integrations.inbound_webhooks.providers.acme.tenant_required' => true]);
    whTenant($this, ['id' => 'p2'])->assertStatus(422)->assertJsonPath('code', 'tenant_invalid');
});

test('un recibo de plataforma (sin tenant) corre el handler sin contexto de tenant', function () {
    whTenant($this, ['id' => 'p3'])->assertOk();

    expect($this->handler->handled[0]['tenant'])->toBeNull();
});

test('un recibo cuyo tenant ya no existe no se procesa en otro tenant: el job falla', function () {
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'z', 'tenant_id' => $this->b->id, 'payload' => ['id' => 'z']]);
    $receipt->forceFill(['tenant_id' => null])->save();
    // tenant borrado = el recibo desaparece en cascada (FK): no queda nada que procese otro tenant
    $receipt->forceFill(['tenant_id' => $this->a->id])->save();
    $this->a->forceDelete();

    expect(InboundWebhookReceipt::whereKey($receipt->id)->exists())->toBeFalse();
});

test('con multiempresa apagada el resolver se ignora y tenant_id queda en null', function () {
    config(['multi-tenancy.enabled' => false]);

    whTenant($this, ['id' => 'off', 'tenant' => $this->a->id])->assertOk();

    expect(InboundWebhookReceipt::first()->tenant_id)->toBeNull();
});

<?php

use App\Contracts\InboundWebhookHandlerInterface;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Jobs\ProcessInboundWebhookJob;
use App\Models\InboundWebhookReceipt;
use App\Models\Mail\MailEvent;
use App\Models\Mail\MailLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingInboundHandler;

uses(RefreshDatabase::class);

const WH_SECRET = 'secreto-de-prueba';

beforeEach(function () {
    // El job corre en la misma petición (el entorno del runner puede traer Redis como cola por defecto)
    config(['queue.default' => 'sync']);
    $this->handler = new RecordingInboundHandler;
    app()->instance(RecordingInboundHandler::class, $this->handler);

    config([
        'integrations.inbound_webhooks.providers.acme' => [
            'secret_config' => 'services.acme.webhook_secret',
            'signature' => ['driver' => 'hmac', 'header' => 'X-Acme-Signature', 'timestamp_header' => 'X-Acme-Timestamp', 'prefix' => 'sha256='],
            'delivery_id' => ['header' => 'X-Acme-Delivery'],
            'handler' => RecordingInboundHandler::class,
            'rules' => ['type' => 'required|string'],
            'store_headers' => ['x-acme-delivery', 'x-acme-signature', 'authorization'],
        ],
        'services.acme.webhook_secret' => WH_SECRET,
    ]);
});

function whSend($test, array $payload = ['type' => 'pago.ok', 'email' => 'ana@example.com'], array $over = [], string $provider = 'acme')
{
    $body = $over['body'] ?? json_encode($payload);
    $ts = $over['ts'] ?? (string) time();
    $sig = $over['sig'] ?? 'sha256='.hash_hmac('sha256', "{$ts}.{$body}", $over['secret'] ?? WH_SECRET);

    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ACME_TIMESTAMP' => $ts, 'HTTP_X_ACME_SIGNATURE' => $sig, 'HTTP_X_ACME_DELIVERY' => $over['delivery'] ?? 'evt_1'];
    foreach ($over['drop'] ?? [] as $h) {
        unset($headers[$h]);
    }

    return $test->call('POST', "/webhooks/inbound/{$provider}", [], [], [], $headers, $body);
}

test('una firma válida guarda el recibo, responde 200 y el job lo procesa', function () {
    whSend($this)->assertOk()->assertJsonPath('data.status', 'received');

    $receipt = InboundWebhookReceipt::firstOrFail();
    expect($receipt->provider)->toBe('acme')
        ->and($receipt->delivery_id)->toBe('evt_1')
        ->and($receipt->status)->toBe('processed')
        ->and($receipt->attempts)->toBe(1)
        ->and($receipt->processed_at)->not->toBeNull()
        ->and($receipt->payload['type'])->toBe('pago.ok')
        ->and($this->handler->handled)->toHaveCount(1);
});

test('con cola real responde rápido, deja el recibo recibido y encola el job', function () {
    Queue::fake();

    whSend($this)->assertOk();

    expect(InboundWebhookReceipt::firstOrFail()->status)->toBe('received');
    Queue::assertPushed(ProcessInboundWebhookJob::class, 1);
    expect($this->handler->handled)->toBe([]);
});

test('una firma inválida da 401 con code estable y no guarda nada', function () {
    whSend($this, over: ['sig' => 'sha256='.str_repeat('0', 64)])
        ->assertStatus(401)->assertJsonPath('code', 'webhook_signature_invalid');

    whSend($this, over: ['secret' => 'otro-secreto'])->assertStatus(401);
    expect(InboundWebhookReceipt::count())->toBe(0);
});

test('una firma que no cubre el cuerpo exacto se rechaza (cuerpo alterado)', function () {
    $ts = (string) time();
    $sig = 'sha256='.hash_hmac('sha256', "{$ts}.".json_encode(['type' => 'pago.ok']), WH_SECRET);

    whSend($this, over: ['ts' => $ts, 'sig' => $sig, 'body' => json_encode(['type' => 'pago.ok', 'monto' => 1])])
        ->assertStatus(401)->assertJsonPath('code', 'webhook_signature_invalid');
});

test('sin cabecera de firma da 401 webhook_signature_missing', function () {
    whSend($this, over: ['drop' => ['HTTP_X_ACME_SIGNATURE']])
        ->assertStatus(401)->assertJsonPath('code', 'webhook_signature_missing');
});

test('un timestamp fuera de la ventana da 401 webhook_timestamp_expired aunque la firma sea correcta', function () {
    whSend($this, over: ['ts' => (string) (time() - 3600)])
        ->assertStatus(401)->assertJsonPath('code', 'webhook_timestamp_expired');

    whSend($this, over: ['ts' => (string) (time() + 3600)])->assertStatus(401);
    expect(InboundWebhookReceipt::count())->toBe(0);
});

test('la ventana de tiempo es configurable por proveedor', function () {
    config(['integrations.inbound_webhooks.providers.acme.signature.tolerance' => 7200]);

    whSend($this, over: ['ts' => (string) (time() - 3600)])->assertOk();
});

test('un proveedor desconocido da 404 y uno sin secreto da 500 sin aceptar nada', function () {
    whSend($this, provider: 'nadie')->assertNotFound()->assertJsonPath('code', 'webhook_provider_unknown');

    config(['services.acme.webhook_secret' => null]);
    whSend($this)->assertStatus(500)->assertJsonPath('code', 'webhook_not_configured');
    expect(InboundWebhookReceipt::count())->toBe(0);
});

test('un duplicado (mismo delivery_id) responde 200 sin reprocesar', function () {
    whSend($this)->assertOk()->assertJsonPath('data.status', 'received');
    whSend($this)->assertOk()->assertJsonPath('data.status', 'duplicate');

    expect(InboundWebhookReceipt::count())->toBe(1)->and($this->handler->handled)->toHaveCount(1);
});

test('el mismo delivery_id en otro proveedor no es duplicado', function () {
    config(['integrations.inbound_webhooks.providers.otro' => config('integrations.inbound_webhooks.providers.acme')]);

    whSend($this)->assertOk();
    whSend($this, provider: 'otro')->assertOk()->assertJsonPath('data.status', 'received');

    expect(InboundWebhookReceipt::count())->toBe(2);
});

test('el payload que no cumple las reglas da 422 y un cuerpo que no es JSON da 400', function () {
    whSend($this, ['otra' => 'cosa'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    whSend($this, over: ['body' => 'no es json'])->assertStatus(400)->assertJsonPath('code', 'webhook_payload_malformed');
    expect(InboundWebhookReceipt::count())->toBe(0);
});

test('un cuerpo mayor que el máximo da 413 antes de verificar nada', function () {
    config(['integrations.inbound_webhooks.max_bytes' => 50]);

    whSend($this, ['type' => 'x', 'relleno' => str_repeat('a', 200)])->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
});

test('sin cabecera de id de entrega se rechaza; con delivery_id null se usa el hash del cuerpo', function () {
    whSend($this, over: ['drop' => ['HTTP_X_ACME_DELIVERY']])->assertStatus(400);

    config(['integrations.inbound_webhooks.providers.acme.delivery_id' => null]);
    whSend($this)->assertOk();
    whSend($this)->assertOk()->assertJsonPath('data.status', 'duplicate');
    expect(InboundWebhookReceipt::first()->delivery_id)->toStartWith('sha256:');
});

test('el id de entrega puede salir del payload', function () {
    config(['integrations.inbound_webhooks.providers.acme.delivery_id' => ['payload' => 'id']]);

    whSend($this, ['type' => 'x', 'id' => 'p_77'])->assertOk();

    expect(InboundWebhookReceipt::first()->delivery_id)->toBe('p_77');
});

test('solo se guardan las cabeceras permitidas y nunca firma ni autorización', function () {
    whSend($this)->assertOk();

    expect(InboundWebhookReceipt::first()->headers)->toBe(['x-acme-delivery' => 'evt_1']);
});

test('la firma rechazada no escribe el secreto ni el payload en el log', function () {
    Log::spy();

    whSend($this, over: ['secret' => 'otro'])->assertStatus(401);

    Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
        $dump = json_encode($ctx);

        return ! str_contains($dump, WH_SECRET) && ! str_contains($dump, 'ana@example.com') && ($ctx['provider'] ?? null) === 'acme';
    })->once();
});

test('si el handler falla el recibo queda failed con el error, y el reintento lo procesa', function () {
    $this->handler->failWith = new RuntimeException('base de datos caída');
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'evt_f', 'payload' => ['type' => 'x']]);

    expect(fn () => (new ProcessInboundWebhookJob($receipt->id))->process(app(TenantContext::class)))
        ->toThrow(RuntimeException::class);

    $receipt->refresh();
    expect($receipt->status)->toBe('failed')->and($receipt->attempts)->toBe(1)
        ->and($receipt->error)->toContain('RuntimeException')->and($receipt->processed_at)->toBeNull();

    // Segundo intento (el de la cola): el handler ya funciona
    $this->handler->failWith = null;
    (new ProcessInboundWebhookJob($receipt->id))->process(app(TenantContext::class));

    $receipt->refresh();
    expect($receipt->status)->toBe('processed')->and($receipt->attempts)->toBe(2)->and($receipt->error)->toBeNull();
});

test('el job lleva reintentos con backoff desde la config', function () {
    $job = new ProcessInboundWebhookJob(1);

    expect($job->tries)->toBe(5)->and($job->backoff)->toBe([30, 120, 600, 1800]);
});

test('un error permanente del handler deja failed sin reintentar (no relanza)', function () {
    $this->handler->failWith = new PermanentIntegrationError('payload imposible');
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'evt_p', 'payload' => ['type' => 'x']]);

    (new ProcessInboundWebhookJob($receipt->id))->process(app(TenantContext::class));

    expect($receipt->refresh()->status)->toBe('failed')->and($receipt->attempts)->toBe(1);
});

test('un recibo ya procesado no se vuelve a ejecutar', function () {
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'evt_d', 'payload' => ['type' => 'x']]);
    $ctx = app(TenantContext::class);

    (new ProcessInboundWebhookJob($receipt->id))->process($ctx);
    (new ProcessInboundWebhookJob($receipt->id))->process($ctx);

    expect($this->handler->handled)->toHaveCount(1)->and($receipt->refresh()->attempts)->toBe(1);
});

test('un proveedor sin handler válido deja el recibo failed', function () {
    config(['integrations.inbound_webhooks.providers.acme.handler' => stdClass::class]);
    $receipt = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'evt_h', 'payload' => ['type' => 'x']]);

    (new ProcessInboundWebhookJob($receipt->id))->process(app(TenantContext::class));

    expect($receipt->refresh()->status)->toBe('failed')->and($receipt->error)->toContain('handler');
});

test('el rate limit corta las ráfagas por proveedor', function () {
    config(['integrations.inbound_webhooks.rate_limit_per_minute' => 2]);

    whSend($this, over: ['delivery' => 'a'])->assertOk();
    whSend($this, over: ['delivery' => 'b'])->assertOk();
    whSend($this, over: ['delivery' => 'c'])->assertStatus(429);
});

test('el barrido reencola recibos atascados y borra los antiguos procesados', function () {
    Queue::fake();
    $viejo = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'old', 'payload' => ['type' => 'x'], 'status' => 'processed']);
    $viejo->forceFill(['created_at' => now()->subDays(60)])->save();
    $atascado = InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'stuck', 'payload' => ['type' => 'x']]);
    $atascado->forceFill(['created_at' => now()->subMinutes(30)])->save();
    InboundWebhookReceipt::create(['provider' => 'acme', 'delivery_id' => 'fresh', 'payload' => ['type' => 'x']]);

    $this->artisan('webhooks:inbound-sweep')->assertSuccessful();

    Queue::assertPushed(ProcessInboundWebhookJob::class, 1);
    expect(InboundWebhookReceipt::pluck('delivery_id')->sort()->values()->all())->toBe(['fresh', 'stuck']);
});

describe('alias de Resend (POST /webhooks/mail) sobre el mecanismo genérico', function () {
    beforeEach(function () {
        $this->svixSecret = 'whsec_'.base64_encode(str_repeat('k', 24));
        config(['services.resend.webhook_secret' => $this->svixSecret]);
    });

    function svixPost($test, array $payload, string $id, ?string $url = '/webhooks/mail', ?string $secret = null)
    {
        $body = json_encode($payload);
        $ts = (string) time();
        $key = base64_decode(substr($secret ?? $test->svixSecret, 6));
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true));

        return $test->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => $sig,
        ], $body);
    }

    test('la URL histórica y la genérica procesan el evento y guardan el MailEvent', function () {
        $id = 'msg_'.bin2hex(random_bytes(4));

        svixPost($this, ['type' => 'email.delivered', 'data' => ['email_id' => 'em_1']], $id)->assertOk();

        $receipt = InboundWebhookReceipt::where('provider', 'resend')->firstOrFail();
        expect($receipt->delivery_id)->toBe($id)->and($receipt->status)->toBe('processed')
            ->and($receipt->headers)->toHaveKey('svix-id')->and($receipt->headers)->not->toHaveKey('svix-signature')
            ->and(MailEvent::where('provider_event_id', $id)->count())->toBe(1);

        svixPost($this, ['type' => 'email.opened', 'data' => ['email_id' => 'em_1']], $id.'b', '/webhooks/inbound/resend')->assertOk();
        expect(InboundWebhookReceipt::where('provider', 'resend')->count())->toBe(2);
    });

    test('un duplicado de Resend no crea otro MailEvent', function () {
        svixPost($this, ['type' => 'email.delivered', 'data' => ['email_id' => 'em_2']], 'msg_dup')->assertOk();
        svixPost($this, ['type' => 'email.delivered', 'data' => ['email_id' => 'em_2']], 'msg_dup')->assertOk()->assertJsonPath('data.status', 'duplicate');

        expect(MailEvent::where('provider_event_id', 'msg_dup')->count())->toBe(1);
    });

    test('firma Svix inválida, caducada o sin secreto se rechaza como antes', function () {
        svixPost($this, ['type' => 'x', 'data' => ['email_id' => 'em']], 'msg_bad', secret: 'whsec_'.base64_encode(str_repeat('z', 24)))
            ->assertStatus(401)->assertJsonPath('code', 'webhook_signature_invalid');

        $body = json_encode(['type' => 'x', 'data' => ['email_id' => 'em']]);
        $old = (string) (time() - 3600);
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', "m.{$old}.{$body}", base64_decode(substr($this->svixSecret, 6)), true));
        $this->call('POST', '/webhooks/mail', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SVIX_ID' => 'm', 'HTTP_SVIX_TIMESTAMP' => $old, 'HTTP_SVIX_SIGNATURE' => $sig], $body)
            ->assertStatus(401)->assertJsonPath('code', 'webhook_timestamp_expired');

        config(['services.resend.webhook_secret' => null]);
        svixPost($this, ['type' => 'x', 'data' => ['email_id' => 'em']], 'msg_nos')->assertStatus(500);
        expect(InboundWebhookReceipt::count())->toBe(0);
    });

    test('un evento sin type o email_id da 422 como antes', function () {
        svixPost($this, ['type' => 'email.delivered'], 'msg_inv')->assertStatus(422);
    });

    test('actualiza el estado del MailLog asociado', function () {
        $log = MailLog::create(['to_email' => 'a@example.com', 'subject' => 'x', 'provider_message_id' => 'em_log', 'status' => 'sent']);

        svixPost($this, ['type' => 'email.delivered', 'data' => ['email_id' => 'em_log']], 'msg_log')->assertOk();

        expect($log->refresh()->status)->not->toBe('sent');
    });
});

test('el handler del contrato se resuelve por interfaz', function () {
    expect(new RecordingInboundHandler)->toBeInstanceOf(InboundWebhookHandlerInterface::class);
});

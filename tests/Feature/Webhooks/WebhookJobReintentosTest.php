<?php

use App\Http\Requests\Webhooks\StoreWebhookRequest;
use App\Jobs\SendWebhookJob;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function jobWebhook(array $extra = []): array
{
    $webhook = Webhook::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id, 'url' => 'https://example.com/hook', 'events' => null,
        'status' => 'active', 'secret' => str_repeat('s', 40), 'old_secret' => null,
        'secret_rotated_at' => null, 'max_retries' => 3, 'timeout' => 10,
    ], $extra));
    $delivery = WebhookDelivery::create([
        'webhook_id' => $webhook->id, 'event_type' => 'user.updated', 'payload' => ['a' => 1],
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);

    return [$webhook, $delivery];
}

test('el job de webhooks usa un solo mecanismo de reintento: Laravel no reintenta', function () {
    $job = new SendWebhookJob(1, 1);

    expect($job->tries)->toBe(1)->and($job->backoff)->toBe([]);
});

test('un 4xx del destino es un fallo permanente y no se reencola', function (int $status) {
    Queue::fake();
    Http::fake(['*' => Http::response('no', $status)]);
    [$webhook, $delivery] = jobWebhook();

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();

    Queue::assertNotPushed(SendWebhookJob::class);
    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->fresh()->response_code)->toBe($status);
})->with([400, 401, 404, 410, 422]);

test('un 5xx, 408 o 429 del destino se reencola con backoff', function (int $status) {
    Queue::fake();
    Http::fake(['*' => Http::response('x', $status)]);
    [$webhook, $delivery] = jobWebhook();

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();

    Queue::assertPushed(SendWebhookJob::class, 1);
    expect($delivery->fresh()->attempts)->toBe(1);
})->with([500, 502, 503, 408, 429]);

test('un error de conexion se reencola', function () {
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('timeout'));
    [$webhook, $delivery] = jobWebhook();

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();

    Queue::assertPushed(SendWebhookJob::class, 1);
});

test('al agotar max_retries no se reencola mas', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('x', 500)]);
    [$webhook, $delivery] = jobWebhook(['max_retries' => 2]);
    $delivery->update(['attempts' => 1]);

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();

    Queue::assertNotPushed(SendWebhookJob::class);
    expect($delivery->fresh()->attempts)->toBe(2);
});

test('un 2xx no reencola y una entrega reclamada por otro no se reenvia', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('ok', 200)]);
    [$webhook, $delivery] = jobWebhook();

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();
    Queue::assertNotPushed(SendWebhookJob::class);

    [$webhook2, $delivery2] = jobWebhook();
    $delivery2->update(['status' => WebhookDelivery::STATUS_PROCESSING]);
    (new SendWebhookJob($webhook2->id, $delivery2->id))->handle();

    Http::assertSentCount(1);
});

test('la validacion rechaza un timeout mayor que el maximo configurado', function () {
    config(['webhooks.max_timeout' => 120]);
    $rules = (new StoreWebhookRequest)->rules();

    expect(validator(['timeout' => 121], ['timeout' => $rules['timeout']])->fails())->toBeTrue()
        ->and(validator(['timeout' => 120], ['timeout' => $rules['timeout']])->fails())->toBeFalse();
});

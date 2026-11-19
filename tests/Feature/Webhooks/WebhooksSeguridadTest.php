<?php

use App\Events\UserLoggedIn;
use App\Jobs\SendWebhookJob;
use App\Listeners\WebhookListener;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookUrlGuard;
use App\Services\WebhookService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function whCabecera(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function whUsuario(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());

    return $user;
}

function whCrear(User $dueno, array $extra = []): Webhook
{
    return Webhook::factory()->create(array_merge([
        'user_id' => $dueno->id, 'url' => 'https://example.com/hook', 'events' => null,
        'status' => 'active', 'secret' => str_repeat('s', 40), 'old_secret' => null,
        'secret_rotated_at' => null, 'max_retries' => 3, 'timeout' => 10,
    ], $extra));
}

function whEntrega(Webhook $webhook, array $payload = ['data' => ['user' => ['name' => 'José', 'web' => 'https://a.com/b']]]): WebhookDelivery
{
    return WebhookDelivery::create([
        'webhook_id' => $webhook->id, 'event_type' => 'user.updated', 'payload' => $payload,
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);
}

// ── Ámbito: solo eventos del dueño ───────────────────────────────────────────

test('un webhook solo recibe los eventos de su dueno', function () {
    Queue::fake();
    $ana = whUsuario();
    $beto = whUsuario();
    $hookAna = whCrear($ana);
    $hookBeto = whCrear($beto);

    (new WebhookListener)->handle(new UserLoggedIn($beto, '10.9.9.9', 'agente'));

    expect(WebhookDelivery::where('webhook_id', $hookBeto->id)->count())->toBe(1)
        ->and(WebhookDelivery::where('webhook_id', $hookAna->id)->count())->toBe(0);
});

test('el login de otro usuario no llega al webhook de un tercero aunque escuche todos los eventos', function () {
    Queue::fake();
    $espia = whUsuario();
    whCrear($espia, ['events' => ['user.logged_in']]);

    (new WebhookListener)->handle(new UserLoggedIn(whUsuario(), '10.9.9.9', 'agente'));

    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNotPushed(SendWebhookJob::class);
});

// ── SSRF al crear y actualizar ─────────────────────────────────────────────

test('crear un webhook hacia una direccion interna responde 422', function (string $url) {
    $user = whUsuario();

    $this->withHeaders(whCabecera($user))
        ->postJson('/webhooks', ['name' => 'Malo', 'url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(Webhook::where('user_id', $user->id)->count())->toBe(0);
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'metadata' => 'https://169.254.169.254/latest/meta-data/',
    'red privada' => 'https://192.168.0.10/hook',
    'http' => 'http://example.com/hook',
]);

test('crear un webhook cuyo dominio resuelve a una ip privada responde 422', function () {
    WebhookUrlGuard::resolveUsing(fn () => ['10.0.0.8']);

    $this->withHeaders(whCabecera(whUsuario()))
        ->postJson('/webhooks', ['name' => 'Rebinding', 'url' => 'https://evil.example.com/hook'])
        ->assertUnprocessable();
});

test('actualizar la url de un webhook hacia una direccion interna responde 422', function () {
    $user = whUsuario();
    $webhook = whCrear($user);

    $this->withHeaders(whCabecera($user))
        ->putJson("/webhooks/{$webhook->id}", ['url' => 'https://169.254.169.254/'])
        ->assertUnprocessable();

    expect($webhook->fresh()->url)->toBe('https://example.com/hook');
});

// ── SSRF al enviar ────────────────────────────────────────────────────────

test('no se envia si el destino pasa a resolver a una ip interna despues del alta', function () {
    Http::fake();
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);

    WebhookUrlGuard::resolveUsing(fn () => ['127.0.0.1']);

    expect(app(WebhookService::class)->processDelivery($webhook, $delivery))->toBeFalse();
    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_FAILED);
});

test('una redireccion del destino no se sigue y la entrega falla', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);

    expect(app(WebhookService::class)->processDelivery($webhook, $delivery))->toBeFalse();
    Http::assertSentCount(1);
    expect($delivery->fresh()->response_code)->toBe(302);
});

test('la respuesta del destino se recorta antes de guardarla', function () {
    Http::fake(['*' => Http::response(str_repeat('x', 5000), 200)]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);

    app(WebhookService::class)->processDelivery($webhook, $delivery);

    expect(mb_strlen($delivery->fresh()->response_body))->toBeLessThanOrEqual(1001);
});

test('un usuario no ve las entregas de un webhook ajeno', function () {
    $webhook = whCrear(whUsuario());
    whEntrega($webhook);

    $this->withHeaders(whCabecera(whUsuario()))->getJson("/webhooks/{$webhook->id}/deliveries")->assertNotFound();
});

test('el dueno ve sus entregas', function () {
    $dueno = whUsuario();
    $webhook = whCrear($dueno);
    whEntrega($webhook);

    $this->withHeaders(whCabecera($dueno))->getJson("/webhooks/{$webhook->id}/deliveries")
        ->assertOk()->assertJsonCount(1, 'data');
});

// ── Firma verificable ────────────────────────────────────────────────────

test('la firma enviada se verifica con el cuerpo crudo aunque lleve tildes y barras', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);

    app(WebhookService::class)->processDelivery($webhook, $delivery);

    Http::assertSent(function ($request) use ($webhook) {
        $body = $request->body();

        return str_contains($body, 'José')
            && str_contains($body, 'https://a.com/b')
            && app(WebhookService::class)->verify(
                $body,
                $request->header('X-Webhook-Signature')[0],
                (int) $request->header('X-Webhook-Timestamp')[0],
                $webhook->secret
            );
    });
});

test('el timestamp va firmado: cambiarlo invalida la firma', function () {
    $service = app(WebhookService::class);
    $ts = now()->timestamp;
    $firma = $service->sign($ts, '{"a":1}', 'secreto-de-prueba-muy-largo-123456');

    expect($service->verify('{"a":1}', $firma, $ts, 'secreto-de-prueba-muy-largo-123456'))->toBeTrue()
        ->and($service->verify('{"a":1}', $firma, $ts + 1, 'secreto-de-prueba-muy-largo-123456'))->toBeFalse()
        ->and($service->verify('{"a":2}', $firma, $ts, 'secreto-de-prueba-muy-largo-123456'))->toBeFalse()
        ->and($service->verify('{"a":1}', $firma, $ts, 'otro-secreto'))->toBeFalse();
});

test('un payload capturado no se acepta pasada la tolerancia', function () {
    $service = app(WebhookService::class);
    $ts = now()->subMinutes(10)->timestamp;
    $firma = $service->sign($ts, '{"a":1}', 'secreto-de-prueba-muy-largo-123456');

    expect($service->verify('{"a":1}', $firma, $ts, 'secreto-de-prueba-muy-largo-123456'))->toBeFalse();
});

test('durante la rotacion se firma con el secreto nuevo y el anterior', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = whCrear(whUsuario(), ['secret' => str_repeat('n', 40), 'old_secret' => str_repeat('v', 40), 'secret_rotated_at' => now()]);
    $delivery = whEntrega($webhook);

    app(WebhookService::class)->processDelivery($webhook, $delivery);

    Http::assertSent(function ($request) use ($webhook) {
        $header = $request->header('X-Webhook-Signature')[0];
        $service = app(WebhookService::class);
        $ts = (int) $request->header('X-Webhook-Timestamp')[0];

        return $service->verify($request->body(), $header, $ts, $webhook->secret)
            && $service->verify($request->body(), $header, $ts, $webhook->old_secret);
    });
});

test('el secreto se devuelve al crear y no vuelve a mostrarse', function () {
    $user = whUsuario();

    $res = $this->withHeaders(whCabecera($user))
        ->postJson('/webhooks', ['name' => 'Mio', 'url' => 'https://example.com/hook'])
        ->assertCreated();

    $secret = $res->json('data.secret');
    expect($secret)->toBeString()->and(strlen($secret))->toBeGreaterThanOrEqual(32);

    $id = $res->json('data.id');
    $this->withHeaders(whCabecera($user))->getJson("/webhooks/{$id}")->assertOk()->assertJsonMissingPath('data.secret');
    $this->withHeaders(whCabecera($user))->getJson('/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
    $this->withHeaders(whCabecera($user))->putJson("/webhooks/{$id}", ['name' => 'Otro'])->assertOk()->assertJsonMissingPath('data.secret');

    $this->withHeaders(whCabecera($user))->postJson("/webhooks/{$id}/rotate-secret")
        ->assertOk()->assertJsonPath('data.new_secret', fn ($nuevo) => $nuevo !== $secret);
});

// ── Idempotencia ─────────────────────────────────────────────────────────

test('una entrega ya realizada no se vuelve a enviar', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);
    $service = app(WebhookService::class);

    expect($service->processDelivery($webhook, $delivery))->toBeTrue()
        ->and($service->processDelivery($webhook, WebhookDelivery::find($delivery->id)))->toBeTrue();

    Http::assertSentCount(1);
});

test('una entrega que otra ejecucion esta procesando no se envia dos veces', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);
    $delivery->update(['status' => WebhookDelivery::STATUS_PROCESSING]);

    app(WebhookService::class)->processDelivery($webhook, WebhookDelivery::find($delivery->id));

    Http::assertNothingSent();
});

test('el job no reenvia una entrega ya exitosa', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $webhook = whCrear(whUsuario());
    $delivery = whEntrega($webhook);
    $delivery->update(['status' => WebhookDelivery::STATUS_SUCCESS]);

    (new SendWebhookJob($webhook->id, $delivery->id))->handle();

    Http::assertNothingSent();
});

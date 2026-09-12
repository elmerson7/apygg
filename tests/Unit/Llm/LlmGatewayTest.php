<?php

use App\Models\Llm\LlmArea;
use App\Models\Logs\LlmAuditLog;
use App\Services\LLM\LlmGateway;
use App\Services\LLM\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('llm.url', 'https://llm.ejemplo.test/v1');
    config()->set('llm.api_key', 'test-key');
    config()->set('llm.defaults', ['timeout' => 5, 'max_attempts' => 2, 'max_iterations' => 3]);
    config()->set('llm.circuit', ['failures' => 2, 'open_secs' => 60, 'count_ttl' => 300]);
    config()->set('llm.area_map', []);
    Cache::flush();

    LlmArea::create([
        'key' => 'soporte',
        'name' => 'Soporte',
        'primary_provider' => 'openai',
        'primary_model' => 'modelo-principal',
        'fallbacks' => [['provider' => 'openai', 'model' => 'modelo-respaldo']],
        'is_active' => true,
    ]);

    $this->gateway = fn () => app(LlmGateway::class)->useCase('soporte.clasificar')->module('tests');
});

function fakeChatResponse(string $content, string $model = 'modelo-principal'): array
{
    return [
        'model' => $model,
        'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ];
}

test('el área se deduce del primer segmento del caso de uso', function () {
    expect(app(LlmGateway::class)->useCase('soporte.clasificar')->areaKey())->toBe('soporte')
        ->and(app(LlmGateway::class)->useCase('soporte')->areaKey())->toBe('soporte');
});

test('area_map tiene prioridad sobre la convención', function () {
    config()->set('llm.area_map', ['informes.resumen' => 'soporte']);

    expect(app(LlmGateway::class)->useCase('informes.resumen')->areaKey())->toBe('soporte');
});

test('devuelve la respuesta del proveedor y la audita', function () {
    Http::fake(['*' => Http::response(fakeChatResponse('clasificado'))]);

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->isOk())->toBeTrue()
        ->and($response->content)->toBe('clasificado')
        ->and($response->totalTokens())->toBe(10);

    $log = LlmAuditLog::first();

    expect($log->use_case)->toBe('soporte.clasificar')
        ->and($log->module)->toBe('tests')
        ->and($log->status)->toBe(LlmAuditLog::STATUS_SUCCESS)
        ->and($log->total_tokens)->toBe(10);
});

test('pasa al modelo de respaldo cuando el principal falla', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(['error' => 'boom'], 500)
            : Http::response(fakeChatResponse('desde el respaldo', 'modelo-respaldo'));
    });

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->isOk())->toBeTrue()
        ->and($response->content)->toBe('desde el respaldo')
        ->and($calls)->toBe(2);
});

test('si toda la cadena falla devuelve un error de proveedor', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->isOk())->toBeFalse()
        ->and($response->skipReason)->toBe(LlmResponse::SKIP_PROVIDER_ERROR)
        ->and($response->isUnavailable())->toBeFalse()
        ->and(LlmAuditLog::where('status', LlmAuditLog::STATUS_ERROR)->count())->toBe(2);
});

test('sin credenciales no llama a la red', function () {
    config()->set('llm.api_key', '');
    Http::fake();

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->isOk())->toBeFalse()
        ->and($response->skipReason)->toBe(LlmResponse::SKIP_NOT_CONFIGURED)
        ->and($response->isUnavailable())->toBeTrue();

    Http::assertNothingSent();
});

test('un área inactiva deja el caso de uso sin IA', function () {
    LlmArea::where('key', 'soporte')->update(['is_active' => false]);
    Http::fake();

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->skipReason)->toBe(LlmResponse::SKIP_NOT_CONFIGURED);
    Http::assertNothingSent();
});

test('el feature flag apaga el caso de uso', function () {
    config()->set('features.llm.soporte.clasificar', ['enabled' => false]);
    Http::fake();

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    expect($response->skipReason)->toBe(LlmResponse::SKIP_FLAG_DISABLED);
    Http::assertNothingSent();
});

test('el circuit breaker se abre tras los fallos configurados', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    // Cada intento recorre la cadena (2 modelos) → 2 fallos, alcanza el umbral
    ($this->gateway)()->chat([['role' => 'user', 'content' => 'hola']]);

    $sentBefore = count(Http::recorded());

    $response = ($this->gateway)()->chat([['role' => 'user', 'content' => 'otra']]);

    expect($response->skipReason)->toBe(LlmResponse::SKIP_CIRCUIT_OPEN)
        ->and(count(Http::recorded()))->toBe($sentBefore);
});

test('structured decodifica el JSON de la respuesta', function () {
    Http::fake(['*' => Http::response(fakeChatResponse('```json{"etiqueta":"factura"}```'))]);

    expect(($this->gateway)()->structured([['role' => 'user', 'content' => 'clasifica']]))
        ->toBe(['etiqueta' => 'factura']);
});

test('structured reintenta cuando la respuesta no es JSON', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(fakeChatResponse($calls === 1 ? 'no soy json' : '{"ok":true}'));
    });

    expect(($this->gateway)()->structured([['role' => 'user', 'content' => 'x']]))->toBe(['ok' => true])
        ->and($calls)->toBe(2);
});

test('structured devuelve null si nunca llega JSON válido', function () {
    Http::fake(['*' => Http::response(fakeChatResponse('sigo sin ser json'))]);

    expect(($this->gateway)()->structured([['role' => 'user', 'content' => 'x']]))->toBeNull();
});

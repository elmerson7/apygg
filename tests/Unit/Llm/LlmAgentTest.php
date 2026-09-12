<?php

use App\Models\Llm\LlmArea;
use App\Services\LLM\LlmAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('llm.url', 'https://llm.ejemplo.test/v1');
    config()->set('llm.api_key', 'test-key');
    config()->set('llm.defaults', ['timeout' => 5, 'max_attempts' => 2, 'max_iterations' => 3]);
    config()->set('llm.circuit', ['failures' => 99, 'open_secs' => 60, 'count_ttl' => 300]);
    Cache::flush();

    LlmArea::create([
        'key' => 'agente',
        'name' => 'Agente',
        'primary_provider' => 'openai',
        'primary_model' => 'modelo',
        'is_active' => true,
    ]);

    $this->tools = [[
        'type' => 'function',
        'function' => ['name' => 'sumar', 'description' => 'suma', 'parameters' => []],
    ]];
});

function toolCallResponse(string $name, array $args): array
{
    return [
        'model' => 'modelo',
        'choices' => [['message' => [
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'function' => ['name' => $name, 'arguments' => json_encode($args)],
            ]],
        ]]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];
}

function finalResponse(string $content): array
{
    return [
        'model' => 'modelo',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];
}

function runAgent(array $tools, callable $executor)
{
    return app(LlmAgent::class)
        ->useCase('agente.test')
        ->module('tests')
        ->run(
            messages: [['role' => 'user', 'content' => 'cuánto es 2+2']],
            tools: $tools,
            executor: $executor,
        );
}

test('devuelve la respuesta directa cuando el modelo no pide herramientas', function () {
    Http::fake(['*' => Http::response(finalResponse('son 4'))]);

    $result = runAgent($this->tools, fn () => []);

    expect($result['ok'])->toBeTrue()
        ->and($result['message']['content'])->toBe('son 4')
        ->and($result['toolCalls'])->toBe(0);
});

test('ejecuta la herramienta y devuelve el resultado al modelo', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(toolCallResponse('sumar', ['a' => 2, 'b' => 2]))
            : Http::response(finalResponse('son 4'));
    });

    $executed = [];

    $result = runAgent($this->tools, function (string $name, array $args) use (&$executed) {
        $executed[] = [$name, $args];

        return ['resultado' => 4];
    });

    expect($result['ok'])->toBeTrue()
        ->and($result['toolCalls'])->toBe(1)
        ->and($executed)->toBe([['sumar', ['a' => 2, 'b' => 2]]]);
});

test('un fallo de la herramienta se le devuelve al modelo en vez de abortar', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(toolCallResponse('sumar', []))
            : Http::response(finalResponse('no pude calcularlo'));
    });

    $result = runAgent($this->tools, function () {
        throw new RuntimeException('faltan argumentos');
    });

    expect($result['ok'])->toBeTrue()
        ->and($result['message']['content'])->toBe('no pude calcularlo')
        ->and($result['toolCalls'])->toBe(1);
});

test('se detiene al alcanzar el máximo de iteraciones', function () {
    // El modelo pide herramienta indefinidamente
    Http::fake(['*' => Http::response(toolCallResponse('sumar', ['a' => 1]))]);

    $result = runAgent($this->tools, fn () => ['resultado' => 1]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('se alcanzó el máximo de iteraciones')
        ->and($result['toolCalls'])->toBe(3);  // max_iterations
});

test('propaga el fallo cuando el LLM no está disponible', function () {
    config()->set('llm.api_key', '');
    Http::fake();

    $result = runAgent($this->tools, fn () => []);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBeNull();
});

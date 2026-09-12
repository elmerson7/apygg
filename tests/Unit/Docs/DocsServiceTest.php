<?php

use App\Models\Docs\DocChunk;
use App\Models\Docs\DocConversation;
use App\Models\User;
use App\Services\Docs\DocsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->docs = app(DocsService::class);

    DocChunk::create([
        'doc_id' => 'docs/cache.md',
        'scope' => 'general',
        'title' => 'Cache',
        'heading' => 'Invalidación',
        'content' => 'Para invalidar la caché de un listado público se llama al método forget del servicio.',
        'position' => 0,
    ]);

    DocChunk::create([
        'doc_id' => 'docs/backups.md',
        'scope' => 'general',
        'title' => 'Backups',
        'heading' => 'Restaurar',
        'content' => 'La restauración de un respaldo sobrescribe la base de datos actual por completo.',
        'position' => 0,
    ]);
});

test('encuentra el fragmento relevante', function () {
    $results = $this->docs->search('invalidar caché');

    expect($results)->not->toBeEmpty()
        ->and($results->first()->doc_id)->toBe('docs/cache.md');
});

test('cae en búsqueda literal cuando el full-text no encuentra nada', function () {
    // 'forget' es un término técnico que el diccionario español no reduce
    $results = $this->docs->search('forget');

    expect($results)->not->toBeEmpty()
        ->and($results->first()->doc_id)->toBe('docs/cache.md');
});

test('una búsqueda vacía no devuelve nada', function () {
    expect($this->docs->search('   '))->toBeEmpty();
});

test('respeta el scope', function () {
    expect($this->docs->search('caché', 'otro-scope'))->toBeEmpty();
});

test('lista los documentos indexados con su número de fragmentos', function () {
    $docs = collect($this->docs->listDocs());

    expect($docs)->toHaveCount(2)
        ->and($docs->firstWhere('doc_id', 'docs/cache.md')['chunks'])->toBe(1);
});

test('sin resultados responde que no encontró nada, sin llamar al LLM', function () {
    config()->set('llm.api_key', '');

    $result = $this->docs->ask('algo que no está documentado en ninguna parte');

    expect($result['answer'])->toContain('No encontré nada')
        ->and($result['sources'])->toBeEmpty();
});

test('sin LLM devuelve igualmente las fuentes encontradas', function () {
    config()->set('llm.url', '');
    config()->set('llm.api_key', '');

    $result = $this->docs->ask('invalidar caché');

    expect($result['available'])->toBeFalse()
        ->and($result['sources'])->not->toBeEmpty()
        ->and($result['sources'][0]['reference'])->toBe('docs/cache.md › Invalidación');
});

test('la conversación acumula el historial', function () {
    config()->set('llm.api_key', '');

    $conversation = DocConversation::create([
        'user_id' => User::factory()->create()->id,
        'scope' => 'general',
        'messages' => [],
    ]);

    $this->docs->askInConversation($conversation, '¿Cómo invalido la caché?');

    $conversation->refresh();

    expect($conversation->messages)->toHaveCount(2)
        ->and($conversation->messages[0]['role'])->toBe('user')
        ->and($conversation->messages[1]['role'])->toBe('assistant')
        ->and($conversation->title)->toBe('¿Cómo invalido la caché?');
});

test('el historial para el prompt se limita a los últimos turnos', function () {
    $conversation = DocConversation::create(['scope' => 'general', 'messages' => []]);

    for ($i = 0; $i < 10; $i++) {
        $conversation->appendExchange("pregunta {$i}", "respuesta {$i}");
    }

    expect($conversation->fresh()->historyForPrompt(2))->toHaveCount(4);
});

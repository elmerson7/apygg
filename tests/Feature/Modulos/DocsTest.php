<?php

use App\Models\Docs\DocChunk;
use App\Models\Docs\DocConversation;
use App\Models\Llm\LlmArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /docs. Todos exigen JWT. Las conversaciones son privadas: un
 * usuario nunca ve ni continúa el hilo de otro. El LLM se simula con Http::fake
 * y, sin área configurada, el asistente responde sin modelo (available = false).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('llm.url', '');
    config()->set('llm.api_key', '');
    config()->set('llm.defaults', ['timeout' => 5, 'max_attempts' => 1, 'max_iterations' => 1]);

    DocChunk::create([
        'doc_id' => 'docs/cache.md',
        'scope' => 'general',
        'title' => 'Cache',
        'heading' => 'Invalidación',
        'content' => 'Para invalidar la caché de un listado público se llama al método forget del servicio.',
        'position' => 0,
    ]);
});

function docsCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function docsUsuario(): User
{
    return User::factory()->create();
}

function docsActivarLlm(): void
{
    config()->set('llm.url', 'https://llm.ejemplo.test/v1');
    config()->set('llm.api_key', 'clave-de-prueba');

    LlmArea::create([
        'key' => 'docs',
        'name' => 'Documentación',
        'primary_provider' => 'openai',
        'primary_model' => 'modelo-docs',
        'fallbacks' => [],
        'is_active' => true,
    ]);
}

// ── índice y búsqueda ───────────────────────────────────────────────────────

test('un usuario autenticado lista los documentos indexados', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('la documentación sin token responde 401', function () {
    $this->getJson('/docs')->assertUnauthorized();
    $this->getJson('/docs/search?q=cache')->assertUnauthorized();
    $this->postJson('/docs/ask', ['question' => 'que es la cache'])->assertUnauthorized();
});

test('la búsqueda devuelve el fragmento con su referencia', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/search?q=invalidar caché')
        ->assertOk()
        ->assertJsonPath('data.0.doc_id', 'docs/cache.md')
        ->assertJsonPath('data.0.heading', 'Invalidación');
});

test('la búsqueda no devuelve fragmentos de otro ámbito', function () {
    DocChunk::create([
        'doc_id' => 'docs/interno.md',
        'scope' => 'interno',
        'title' => 'Interno',
        'heading' => 'Caché',
        'content' => 'Detalle interno sobre la caché del servicio.',
        'position' => 0,
    ]);

    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/search?q=caché&scope=general')
        ->assertOk()
        ->assertJsonMissing(['doc_id' => 'docs/interno.md']);
});

test('la búsqueda sin término responde 422', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/search')
        ->assertUnprocessable();
});

test('la búsqueda con un término de una letra responde 422', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/search?q=a')
        ->assertUnprocessable();
});

test('la búsqueda limita el número de resultados entre 1 y 20', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/search?q=cache&limit=50')
        ->assertUnprocessable();
});

// ── preguntar al asistente ──────────────────────────────────────────────────

test('preguntar sin fragmentos relevantes responde sin llamar al modelo', function () {
    Http::fake();
    docsActivarLlm();

    $this->withHeaders(docsCabeceras(docsUsuario()))->postJson('/docs/ask', [
        'question' => 'cómo se cocina una tortilla',
    ])->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.sources', []);

    Http::assertNothingSent();
});

test('preguntar sin modelo configurado devuelve los fragmentos sin respuesta generada', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->postJson('/docs/ask', [
        'question' => 'como invalidar la caché',
    ])->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.sources.0.doc_id', 'docs/cache.md');
});

test('preguntar con modelo disponible devuelve la respuesta y crea la conversación', function () {
    Http::fake(['*' => Http::response([
        'model' => 'modelo-docs',
        'choices' => [['message' => ['content' => 'Usa el método forget.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ], 200)]);
    docsActivarLlm();
    $usuario = docsUsuario();

    $this->withHeaders(docsCabeceras($usuario))->postJson('/docs/ask', [
        'question' => 'como invalidar la caché',
    ])->assertOk()
        ->assertJsonPath('data.answer', 'Usa el método forget.')
        ->assertJsonPath('data.available', true);

    $this->assertDatabaseHas('doc_conversations', ['user_id' => $usuario->id, 'scope' => 'general']);
});

test('preguntar sin pregunta o demasiado corta responde 422', function () {
    $usuario = docsUsuario();

    $this->withHeaders(docsCabeceras($usuario))->postJson('/docs/ask', [])->assertUnprocessable();
    $this->withHeaders(docsCabeceras($usuario))->postJson('/docs/ask', ['question' => 'ab'])->assertUnprocessable();
});

test('continuar un hilo propio añade los dos mensajes al historial', function () {
    $usuario = docsUsuario();
    $hilo = DocConversation::create(['user_id' => $usuario->id, 'scope' => 'general', 'messages' => []]);

    $this->withHeaders(docsCabeceras($usuario))->postJson('/docs/ask', [
        'question' => 'como invalidar la caché',
        'conversation_id' => $hilo->id,
    ])->assertOk()
        ->assertJsonPath('data.conversation_id', $hilo->id);

    expect(count($hilo->fresh()->messages))->toBe(2);
});

test('continuar el hilo de otro usuario responde 404 y no añade nada', function () {
    $dueno = docsUsuario();
    $hilo = DocConversation::create(['user_id' => $dueno->id, 'scope' => 'general', 'messages' => []]);

    $this->withHeaders(docsCabeceras(docsUsuario()))->postJson('/docs/ask', [
        'question' => 'como invalidar la caché',
        'conversation_id' => $hilo->id,
    ])->assertNotFound();

    expect($hilo->fresh()->messages)->toBe([]);
});

test('continuar un hilo que no existe responde 422', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->postJson('/docs/ask', [
        'question' => 'como invalidar la caché',
        'conversation_id' => 999999,
    ])->assertUnprocessable();
});

// ── conversaciones privadas ─────────────────────────────────────────────────

test('cada usuario lista solo sus propias conversaciones', function () {
    $ana = docsUsuario();
    $luis = docsUsuario();
    $suya = DocConversation::create(['user_id' => $ana->id, 'scope' => 'general', 'messages' => []]);
    $ajena = DocConversation::create(['user_id' => $luis->id, 'scope' => 'general', 'messages' => []]);

    $ids = collect($this->withHeaders(docsCabeceras($ana))->getJson('/docs/conversations')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($suya->id)->not->toContain($ajena->id);
});

test('ver la conversación de otro usuario responde 403', function () {
    $dueno = docsUsuario();
    $hilo = DocConversation::create(['user_id' => $dueno->id, 'scope' => 'general', 'messages' => []]);

    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson("/docs/conversations/{$hilo->id}")
        ->assertForbidden();
});

test('el dueño ve su conversación por id', function () {
    $dueno = docsUsuario();
    $hilo = DocConversation::create(['user_id' => $dueno->id, 'scope' => 'general', 'messages' => []]);

    $this->withHeaders(docsCabeceras($dueno))->getJson("/docs/conversations/{$hilo->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $hilo->id);
});

test('pedir una conversación inexistente responde 404', function () {
    $this->withHeaders(docsCabeceras(docsUsuario()))->getJson('/docs/conversations/999999')
        ->assertNotFound();
});

test('las conversaciones sin token responden 401', function () {
    $this->getJson('/docs/conversations')->assertUnauthorized();
});

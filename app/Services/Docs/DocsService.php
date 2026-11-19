<?php

namespace App\Services\Docs;

use App\Contracts\DocsServiceInterface;
use App\Models\Docs\DocChunk;
use App\Models\Docs\DocConversation;
use App\Services\LLM\LlmGateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DocsService
 *
 * Búsqueda y consulta en lenguaje natural sobre la documentación del proyecto.
 *
 * Es un RAG sencillo: se recuperan los fragmentos relevantes con el full-text
 * de PostgreSQL y se le pasan al modelo como contexto. Sin embeddings ni
 * extensiones: para la documentación de un proyecto —decenas de documentos, no
 * millones— la búsqueda léxica da resultados equiparables a un coste mucho
 * menor.
 *
 * El modelo responde **solo** con lo que hay en los fragmentos. Un asistente de
 * documentación que improvisa es peor que no tener asistente: da respuestas
 * plausibles y equivocadas sobre tu propio sistema.
 */
class DocsService implements DocsServiceInterface
{
    /**
     * Buscar fragmentos relevantes.
     *
     * @return Collection<int, DocChunk>
     */
    public function search(string $query, string $scope = 'general', ?int $limit = null): Collection
    {
        $limit ??= (int) config('docs-assistant.retrieval.limit', 5);
        $query = trim($query);

        if ($query === '') {
            return new Collection;
        }

        $config = config('docs-assistant.text_search_config', 'spanish');

        // websearch_to_tsquery entiende comillas y operadores como un buscador
        // web, y —a diferencia de to_tsquery— no revienta con entrada libre.
        $results = DocChunk::query()
            ->forScope($scope)
            ->whereRaw("search_vector @@ websearch_to_tsquery('{$config}', ?)", [$query])
            ->select('*')
            ->selectRaw("ts_rank(search_vector, websearch_to_tsquery('{$config}', ?)) AS rank", [$query])
            ->orderByDesc('rank')
            ->limit($limit)
            ->get();

        // Si el full-text no encuentra nada (términos muy raros, nombres de
        // clases), se intenta una búsqueda literal antes de rendirse.
        if ($results->isEmpty()) {
            $results = $this->literalSearch($query, $scope, $limit);
        }

        return $results;
    }

    /**
     * @return Collection<int, DocChunk>
     */
    protected function literalSearch(string $query, string $scope, int $limit): Collection
    {
        return DocChunk::query()
            ->forScope($scope)
            ->where(fn ($q) => $q->where('content', 'ILIKE', '%'.$query.'%')
                ->orWhere('heading', 'ILIKE', '%'.$query.'%')
                ->orWhere('title', 'ILIKE', '%'.$query.'%'))
            ->orderBy('position')
            ->limit($limit)
            ->get();
    }

    /**
     * Documentos indexados y cuántos fragmentos tiene cada uno.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDocs(string $scope = 'general'): array
    {
        $rows = DocChunk::query()
            ->forScope($scope)
            ->select('doc_id', 'title', DB::raw('COUNT(*) as chunks'), DB::raw('MAX(updated_at) as indexed_at'))
            ->groupBy('doc_id', 'title')
            ->orderBy('doc_id')
            ->get();

        $docs = [];

        foreach ($rows as $row) {
            $docs[] = [
                'doc_id' => $row->doc_id,
                'title' => $row->title,
                'chunks' => (int) $row->chunks,
                'indexed_at' => $row->indexed_at,
            ];
        }

        return $docs;
    }

    /**
     * Preguntar a la documentación.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, sources: array<int, array<string, mixed>>, available: bool}
     */
    public function ask(string $question, string $scope = 'general', array $history = []): array
    {
        $chunks = $this->search($question, $scope);

        if ($chunks->isEmpty()) {
            return [
                'answer' => 'No encontré nada en la documentación sobre eso.',
                'sources' => [],
                'available' => true,
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ...$history,
            ['role' => 'user', 'content' => $this->userPrompt($question, $chunks)],
        ];

        $response = app(LlmGateway::class)
            ->useCase('docs.ask')
            ->module('docs')
            ->chat($messages);

        if (! $response->isOk()) {
            // Sin modelo, se devuelven igualmente los fragmentos encontrados:
            // el buscador sigue siendo útil aunque no haya quien redacte.
            return [
                'answer' => $response->isUnavailable()
                    ? 'El asistente no está disponible. Estos son los fragmentos relevantes que encontré.'
                    : 'No pude generar una respuesta. Estos son los fragmentos relevantes que encontré.',
                'sources' => $this->sources($chunks),
                'available' => false,
            ];
        }

        return [
            'answer' => $response->content,
            'sources' => $this->sources($chunks),
            'available' => true,
        ];
    }

    /**
     * Preguntar dentro de un hilo, conservando el historial.
     *
     * @return array{answer: string, sources: array<int, array<string, mixed>>, available: bool, conversation_id: int}
     */
    public function askInConversation(DocConversation $conversation, string $question): array
    {
        $result = $this->ask($question, $conversation->scope, $conversation->historyForPrompt());

        $conversation->appendExchange($question, $result['answer'], $result['sources']);

        return [...$result, 'conversation_id' => $conversation->id];
    }

    protected function systemPrompt(): string
    {
        return 'Eres un asistente que responde preguntas sobre la documentación técnica de un proyecto. '
            .'Responde ÚNICAMENTE con la información de los fragmentos que se te proporcionan. '
            .'Si los fragmentos no contienen la respuesta, di claramente que la documentación no lo cubre; '
            .'no inventes ni completes con conocimiento general. '
            .'Cita las secciones en las que te apoyas. Sé conciso y directo.';
    }

    /**
     * @param  Collection<int, DocChunk>  $chunks
     */
    protected function userPrompt(string $question, Collection $chunks): string
    {
        $context = $chunks
            ->map(fn (DocChunk $chunk) => "### Fuente: {$chunk->reference()}\n{$chunk->content}")
            ->implode("\n\n---\n\n");

        return <<<PROMPT
        FRAGMENTOS DE LA DOCUMENTACIÓN:

        {$context}

        ---

        PREGUNTA: {$question}
        PROMPT;
    }

    /**
     * @param  Collection<int, DocChunk>  $chunks
     * @return array<int, array<string, mixed>>
     */
    protected function sources(Collection $chunks): array
    {
        return $chunks->map(fn (DocChunk $chunk) => [
            'doc_id' => $chunk->doc_id,
            'title' => $chunk->title,
            'heading' => $chunk->heading,
            'reference' => $chunk->reference(),
        ])->all();
    }
}

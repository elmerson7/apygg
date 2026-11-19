<?php

namespace App\Contracts;

use App\Models\Docs\DocChunk;
use App\Models\Docs\DocConversation;
use Illuminate\Database\Eloquent\Collection;

/**
 * DocsServiceInterface
 *
 * Contrato de la búsqueda y preguntas sobre la documentación.
 */
interface DocsServiceInterface
{
    /**
     * Buscar fragmentos relevantes.
     *
     * @return Collection<int, DocChunk>
     */
    public function search(string $query, string $scope = 'general', ?int $limit = null): Collection;

    /**
     * Documentos indexados y cuántos fragmentos tiene cada uno.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDocs(string $scope = 'general'): array;

    /**
     * Preguntar a la documentación.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, sources: array<int, array<string, mixed>>, available: bool}
     */
    public function ask(string $question, string $scope = 'general', array $history = []): array;

    /**
     * Preguntar dentro de un hilo, conservando el historial.
     *
     * @return array{answer: string, sources: array<int, array<string, mixed>>, available: bool, conversation_id: int}
     */
    public function askInConversation(DocConversation $conversation, string $question): array;
}

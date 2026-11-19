<?php

namespace App\Http\Controllers\Docs;

use App\Contracts\DocsServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Docs\AskDocsRequest;
use App\Models\Docs\DocChunk;
use App\Models\Docs\DocConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DocsController
 *
 * Búsqueda y consulta sobre la documentación indexada.
 */
class DocsController extends Controller
{
    public function __construct(private readonly DocsServiceInterface $docs) {}

    /**
     * Documentos indexados.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->string('scope', 'general')->toString();

        return $this->sendSuccess(
            $this->docs->listDocs($scope),
            'Documentos obtenidos exitosamente'
        );
    }

    /**
     * Búsqueda directa, sin LLM.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:500'],
            'scope' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $chunks = $this->docs->search(
            $validated['q'],
            $validated['scope'] ?? 'general',
            $validated['limit'] ?? null
        );

        return $this->sendSuccess(
            $chunks->map(fn (DocChunk $chunk) => [
                'doc_id' => $chunk->doc_id,
                'title' => $chunk->title,
                'heading' => $chunk->heading,
                'reference' => $chunk->reference(),
                'content' => $chunk->content,
            ]),
            'Resultados obtenidos exitosamente'
        );
    }

    /**
     * Preguntar en lenguaje natural. Con `conversation_id` continúa un hilo.
     */
    public function ask(AskDocsRequest $request): JsonResponse
    {
        $scope = $request->validated('scope') ?? 'general';

        $conversation = $request->filled('conversation_id')
            ? DocConversation::where('user_id', $request->user()->id)
                ->findOrFail($request->validated('conversation_id'))
            : DocConversation::create([
                'user_id' => $request->user()->id,
                'scope' => $scope,
                'messages' => [],
            ]);

        return $this->sendSuccess(
            $this->docs->askInConversation($conversation, $request->validated('question')),
            'Respuesta generada exitosamente'
        );
    }

    /**
     * Hilos del usuario autenticado.
     */
    public function conversations(Request $request): JsonResponse
    {
        $conversations = DocConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($request, 15));

        return $this->sendPaginated($conversations);
    }

    public function conversation(Request $request, DocConversation $docConversation): JsonResponse
    {
        abort_unless($docConversation->user_id === $request->user()->id, 403);

        return $this->sendSuccess($docConversation, 'Conversación obtenida exitosamente');
    }
}

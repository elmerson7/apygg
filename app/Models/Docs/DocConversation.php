<?php

namespace App\Models\Docs;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocConversation Model
 *
 * Hilo de preguntas sobre la documentación. Guardar el historial permite
 * repreguntar ("¿y cómo lo configuro?") sin que el usuario tenga que repetir
 * el contexto.
 *
 * @property int $id
 * @property array<int, array{role: string, content: string}> $messages
 */
class DocConversation extends Model
{
    protected $table = 'doc_conversations';

    protected $fillable = [
        'user_id',
        'scope',
        'title',
        'messages',
    ];

    protected $casts = [
        'messages' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Añadir un intercambio al hilo.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    public function appendExchange(string $question, string $answer, array $sources = []): void
    {
        $messages = $this->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $question];
        $messages[] = ['role' => 'assistant', 'content' => $answer, 'sources' => $sources];

        $this->update([
            'messages' => $messages,
            'title' => $this->title ?: mb_substr($question, 0, 80),
        ]);
    }

    /**
     * Historial en el formato que espera el LLM, limitado a los últimos turnos.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function historyForPrompt(int $turns = 6): array
    {
        return collect($this->messages ?? [])
            ->slice(-$turns * 2)
            ->map(fn (array $m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->all();
    }
}

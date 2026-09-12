<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LlmProvider;
use App\Services\LLM\LlmResponse;

/**
 * NullProvider
 *
 * Se usa cuando el LLM no está configurado (sin URL o sin API key). Devuelve
 * siempre una respuesta "skipped" sin hacer ninguna llamada de red, de modo que
 * los servicios que dependen de IA degradan de forma limpia en lugar de fallar.
 */
final class NullProvider implements LlmProvider
{
    public function chat(array $messages, array $options = []): LlmResponse
    {
        return LlmResponse::skipped('LLM no configurado');
    }

    public function chatStream(array $messages, callable $onChunk, array $options = []): void
    {
        $onChunk('El asistente no está configurado.');
    }

    public function embed(string $text, array $options = []): ?array
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return false;
    }
}

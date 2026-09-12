<?php

namespace App\Services\LLM\Providers;

/**
 * OpenAiProvider
 *
 * Proveedor para OpenAI y para cualquier pasarela compatible 1:1 con su API
 * (OpenRouter, Groq, Together, vLLM, LM Studio…). Sin particularidades: el
 * formato de la respuesta se negocia por prompt desde el gateway.
 */
final class OpenAiProvider extends AbstractHttpProvider
{
    protected function defaultOptions(): array
    {
        return [];
    }
}

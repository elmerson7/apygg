<?php

namespace App\Services\LLM\Providers;

/**
 * DeepSeekProvider
 *
 * Proveedor para DeepSeek (API compatible con OpenAI). Aquí viven sus
 * particularidades:
 *
 * - `response_format: json_object` para salidas estructuradas.
 * - `thinking: {type: disabled}` porque el modelo razona por defecto, consume
 *   el límite de tokens en `reasoning_content` y devuelve contenido vacío.
 *
 * Sirve de ejemplo de cómo aislar los caprichos de un proveedor sin ensuciar
 * la base común.
 */
final class DeepSeekProvider extends AbstractHttpProvider
{
    protected function defaultOptions(): array
    {
        return [
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'disabled'],
        ];
    }
}

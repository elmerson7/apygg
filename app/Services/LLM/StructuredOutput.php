<?php

namespace App\Services\LLM;

/**
 * StructuredOutput
 *
 * Extracción de salidas estructuradas (JSON) desde la respuesta de un LLM.
 *
 * Los modelos devuelven el JSON envuelto de formas muy distintas —dentro de un
 * bloque markdown, con una frase antes, con tokens de razonamiento— y cada
 * servicio que lo necesitaba acababa escribiendo su propio parser frágil.
 * Aquí está una sola vez.
 */
final class StructuredOutput
{
    /**
     * Extraer un array asociativo del contenido devuelto por el LLM.
     *
     * Tolera bloques de código markdown, texto antes o después y contenido
     * parcial. Devuelve null si no hay JSON válido.
     *
     * @return array<mixed>|null
     */
    public static function extract(string $content): ?array
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        // Quitar los code fences de markdown
        $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = (string) preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        // Intento directo
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Buscar el primer objeto o array balanceado dentro del texto
        $open = strcspn($content, '{[');

        if ($open === strlen($content)) {
            return null;
        }

        $closeChar = $content[$open] === '{' ? '}' : ']';
        $end = strrpos($content, $closeChar);

        if ($end === false || $end <= $open) {
            return null;
        }

        $decoded = json_decode(substr($content, $open, $end - $open + 1), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : null;
    }
}

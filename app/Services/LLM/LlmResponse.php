<?php

namespace App\Services\LLM;

/**
 * LlmResponse
 *
 * DTO inmutable con el resultado de una llamada al LLM. Siempre se devuelve una
 * instancia, nunca null: los fallos se representan con `success = false` vía el
 * factory `skipped()`, de modo que quien consume no tiene que null-checkear.
 *
 * `skipReason` distingue POR QUÉ no hubo respuesta, y esa distinción importa:
 * que el LLM no esté configurado no es lo mismo que un fallo del proveedor.
 * Sin ella, un módulo muestra "error de IA" cuando en realidad nunca se
 * configuró un modelo.
 */
final class LlmResponse
{
    /** No hay credenciales, o el área no tiene modelo / está inactiva. */
    public const SKIP_NOT_CONFIGURED = 'not_configured';

    /** El caso de uso está apagado por feature flag. */
    public const SKIP_FLAG_DISABLED = 'flag_disabled';

    /** El circuit breaker del área está abierto. */
    public const SKIP_CIRCUIT_OPEN = 'circuit_open';

    /** El proveedor respondió mal o no respondió. Es el único que es incidente. */
    public const SKIP_PROVIDER_ERROR = 'provider_error';

    /**
     * @param  array<int, array<string, mixed>>|null  $toolCalls
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $content = '',
        public readonly string $model = '',
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly bool $success = true,
        public readonly ?string $error = null,
        public readonly ?string $skipReason = null,
        public readonly ?array $toolCalls = null,
        public readonly ?string $finishReason = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Fallo controlado. `$skipReason` debe ser una de las constantes SKIP_*
     * para que quien consume pueda decidir cómo mostrarlo.
     */
    public static function skipped(string $reason, string $skipReason = self::SKIP_NOT_CONFIGURED): self
    {
        return new self(
            content: '',
            model: '',
            success: false,
            error: $reason,
            skipReason: $skipReason,
        );
    }

    public function isOk(): bool
    {
        return $this->success;
    }

    /**
     * true cuando el LLM no llegó a ejecutarse por configuración o por una
     * protección (no configurado, flag apagado, circuito abierto). No es un
     * incidente: el módulo debe mostrarlo como "sin análisis", no como error.
     */
    public function isUnavailable(): bool
    {
        return $this->skipReason !== null
            && $this->skipReason !== self::SKIP_PROVIDER_ERROR;
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}

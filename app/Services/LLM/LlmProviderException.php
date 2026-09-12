<?php

namespace App\Services\LLM;

/**
 * LlmProviderException
 *
 * Error de transporte o del proveedor de LLM. `LlmGateway` la captura para
 * alimentar el circuit breaker y la auditoría sin romper el request en curso.
 */
class LlmProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

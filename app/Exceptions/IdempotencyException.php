<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Errores del middleware `idempotent` (docs/idempotency.md).
 */
class IdempotencyException extends ApiException
{
    private ErrorCode $errorCode;

    private function __construct(string $message, int $status, ErrorCode $code, string $type, array $extensions = [])
    {
        parent::__construct($message, $status, [], $type, null, $extensions);
        $this->errorCode = $code;
    }

    public static function required(string $header): self
    {
        return new self("Falta la cabecera {$header}.", 400, ErrorCode::IdempotencyKeyRequired, 'idempotency_key_required');
    }

    public static function invalid(string $header): self
    {
        return new self("La cabecera {$header} no es válida.", 400, ErrorCode::IdempotencyKeyRequired, 'idempotency_key_required');
    }

    public static function mismatch(): self
    {
        return new self('Esta clave de idempotencia ya se usó con otra petición (método, ruta o cuerpo distintos).', 422, ErrorCode::IdempotencyKeyMismatch, 'idempotency_key_mismatch');
    }

    public static function inProgress(int $retryAfter = 1): self
    {
        return new self('Una petición con esta clave de idempotencia sigue en curso.', 409, ErrorCode::IdempotencyKeyInProgress, 'idempotency_key_in_progress', ['retry_after' => $retryAfter]);
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }
}

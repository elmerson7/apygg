<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Fallos del login por código. `otp_invalid` es genérico a propósito: no distingue código mal, caducado, usado,
 * agotado o destino inexistente.
 */
class OtpLoginException extends ApiException
{
    private function __construct(string $message, int $status, private readonly string $errorKey, array $extensions = [])
    {
        parent::__construct($message, $status, [], $errorKey, null, $extensions);
    }

    public static function invalid(): self
    {
        return new self('Código inválido o caducado', 401, 'otp_invalid');
    }

    public static function unavailable(): self
    {
        return new self('Login por código no disponible', 404, 'otp_login_unavailable');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self('Demasiados intentos. Inténtalo más tarde', 429, 'rate_limited', ['retry_after' => $retryAfter]);
    }

    public function errorCode(): ErrorCode|string
    {
        return ErrorCode::tryFrom($this->errorKey) ?? $this->errorKey;
    }
}

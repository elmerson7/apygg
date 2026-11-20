<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Código 2FA o desafío de login inválido, caducado o agotado. Mensaje genérico a propósito:
 * no distingue "código mal" de "desafío caducado" para no ayudar a quien adivina.
 */
class TwoFactorException extends ApiException
{
    public function __construct(string $message = 'Código de verificación inválido', ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, [], 'two_factor_invalid', null, [], $previous);
    }

    public function errorCode(): ErrorCode|string
    {
        return ErrorCode::TwoFactorInvalid;
    }
}

<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * La identidad está en la lista negra: no puede iniciar sesión (403).
 *
 * El mensaje es deliberadamente genérico: no revela que la cuenta esté bloqueada.
 */
class AccountBlockedException extends ApiException
{
    public function __construct(string $message = 'No es posible iniciar sesión con esta cuenta.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, [], 'account_blocked', null, [], $previous);
    }

    public function errorCode(): ErrorCode|string
    {
        return ErrorCode::AccountBlocked;
    }
}

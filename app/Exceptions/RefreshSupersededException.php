<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El refresh token presentado es el inmediatamente anterior y se rotó hace segundos (carrera
 * legítima entre dos peticiones). 401 con código estable `refresh_superseded`; la sesión sigue viva
 * y el cliente debe usar el refresh que le devolvió la otra petición.
 */
class RefreshSupersededException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Este refresh token ya fue renovado. Usa el más reciente.', 401, [], 'refresh_superseded', null, [], $previous);
    }

    public function errorCode(): ErrorCode|string
    {
        return 'refresh_superseded';
    }
}

<?php

namespace App\Exceptions;

/**
 * Demasiados intentos de login fallidos: la identidad (o identidad+IP) está bloqueada.
 *
 * Responde 401 (el contrato histórico del login); el handler global la convierte en RFC 7807.
 */
class LoginLockedException extends ApiException
{
    public function __construct(int $lockoutMinutes, ?\Throwable $previous = null)
    {
        parent::__construct(
            'Demasiados intentos fallidos. Intenta nuevamente en '.$lockoutMinutes.' minutos.',
            401,
            [],
            'login_locked',
            null,
            ['lockout_minutes' => $lockoutMinutes],
            $previous
        );
    }
}

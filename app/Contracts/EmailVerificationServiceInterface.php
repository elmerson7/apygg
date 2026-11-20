<?php

namespace App\Contracts;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Verificación de correo por enlace con token firmado y caducidad. docs/sessions-and-2fa.md.
 */
interface EmailVerificationServiceInterface
{
    /**
     * Envía el correo con el enlace de verificación (en cola).
     */
    public function send(User $user): void;

    /**
     * Como `send`, pero con límite por usuario y hora.
     *
     * @return bool false si se alcanzó el límite (no se envió)
     */
    public function sendThrottled(User $user): bool;

    /**
     * Segundos hasta poder reenviar (0 si ya se puede).
     */
    public function retryAfter(User $user): int;

    /**
     * Valida el token y marca el correo como verificado (idempotente).
     *
     * @throws ApiException 422 `token_invalid` si es inválido, caducado o el correo cambió
     */
    public function verify(string $token): User;

    /**
     * Genera el token firmado (lo usan el correo y los tests).
     */
    public function makeToken(User $user): string;
}

<?php

namespace App\Contracts;

use App\Exceptions\LoginLockedException;
use App\Exceptions\TwoFactorException;
use App\Models\User;

/**
 * 2FA TOTP opcional por usuario para la API. docs/sessions-and-2fa.md.
 */
interface TwoFactorServiceInterface
{
    /** ¿Tiene el usuario 2FA activo (y la función está habilitada)? */
    public function isEnabled(User $user): bool;

    /**
     * @return array{enabled: bool, recovery_codes_remaining: int}
     */
    public function status(User $user): array;

    /**
     * Genera un secreto PENDIENTE (no activa nada hasta confirmar).
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function beginSetup(User $user): array;

    /**
     * Confirma con un código del autenticador, activa el 2FA y devuelve los códigos de
     * recuperación EN CLARO (única vez que se ven).
     *
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code): array;

    /**
     * Desactiva el 2FA. Exige la contraseña y un código vigente (TOTP o de recuperación).
     */
    public function disable(User $user, string $password, string $code): void;

    /**
     * Renueva los códigos de recuperación (invalida los anteriores). Exige contraseña y código.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $password, string $code): array;

    /**
     * Abre un desafío de login (token opaco de un solo uso y vida corta).
     *
     * @return array{challenge_token: string, expires_in: int}
     */
    public function startChallenge(User $user): array;

    /**
     * Resuelve el desafío con un código y lo consume. Devuelve el usuario que lo abrió.
     *
     * @throws TwoFactorException
     * @throws LoginLockedException
     */
    public function resolveChallenge(string $challengeToken, string $code): User;
}

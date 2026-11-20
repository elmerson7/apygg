<?php

namespace App\Support\Privacy;

use App\Contracts\Privacy\DeletionSecondFactorInterface;
use App\Contracts\TwoFactorServiceInterface;
use App\Exceptions\LoginLockedException;
use App\Exceptions\PrivacyException;
use App\Exceptions\TwoFactorException;
use App\Models\User;

/**
 * Si el usuario tiene 2FA activo, el borrado exige un código vigente (TOTP o de recuperación). Reutiliza el
 * desafío de login de `TwoFactorServiceInterface`, que ya limita intentos y consume el código.
 */
class TwoFactorDeletionSecondFactor implements DeletionSecondFactorInterface
{
    public function __construct(private readonly TwoFactorServiceInterface $twoFactor) {}

    public function verify(User $user, ?string $code): void
    {
        if (! $this->twoFactor->isEnabled($user)) {
            return;
        }

        if ($code === null || $code === '') {
            throw PrivacyException::secondFactorInvalid();
        }

        try {
            $challenge = $this->twoFactor->startChallenge($user);
            $this->twoFactor->resolveChallenge($challenge['challenge_token'], $code);
        } catch (TwoFactorException|LoginLockedException) {
            throw PrivacyException::secondFactorInvalid();
        }
    }
}

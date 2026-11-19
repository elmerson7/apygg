<?php

namespace App\Jobs;

use App\Contracts\PasswordServiceInterface;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;

/**
 * SendPasswordResetLinkJob
 *
 * Genera el token de recuperación y envía el correo. Va en cola para que
 * `POST /auth/forgot-password` tarde lo mismo exista o no la cuenta (hashear
 * el token y enviar el correo solo ocurre si existe).
 */
class SendPasswordResetLinkJob extends Job
{
    public function __construct(
        protected string $email,
        protected ?string $resetUrl = null
    ) {
        parent::__construct();
    }

    public function process(PasswordServiceInterface $passwords): void
    {
        $user = User::where('email', $this->email)->first();

        if (! $user) {
            return;
        }

        $token = $passwords->generateResetToken($user);

        $user->notify(new ResetPasswordNotification($token, $this->resetUrl));
    }
}

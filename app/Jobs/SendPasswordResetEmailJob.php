<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\PasswordService;

/**
 * SendPasswordResetEmailJob
 *
 * Job para enviar email de reset de contraseña.
 */
class SendPasswordResetEmailJob extends Job
{
    /**
     * ID del usuario al que se enviará el email
     */
    protected string $userId;

    /**
     * URL personalizada para reset (opcional)
     */
    protected ?string $resetUrl;

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(string $userId, ?string $resetUrl = null)
    {
        parent::__construct();
        $this->userId = $userId;
        $this->resetUrl = $resetUrl;
    }

    /**
     * Ejecutar el job
     */
    protected function process(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            $this->log('warning', 'Usuario no encontrado para enviar email de reset de contraseña', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Generar token de reset usando PasswordService
        $passwordService = app(PasswordService::class);
        $token = $passwordService->generateResetToken($user);

        // Enviar notificación usando ResetPasswordNotification
        $user->notify(new ResetPasswordNotification($token, $this->resetUrl));

        $this->log('info', 'Email de reset de contraseña enviado exitosamente', [
            'user_id' => $user->id,
            'email' => $user->email,
            'reset_url_provided' => $this->resetUrl !== null,
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;

/**
 * SendWelcomeEmailJob
 *
 * Job para enviar email de bienvenida a nuevos usuarios.
 */
class SendWelcomeEmailJob extends Job
{
    /**
     * ID del usuario al que se enviará el email
     */
    protected string $userId;

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(string $userId)
    {
        parent::__construct();
        $this->userId = $userId;
    }

    /**
     * Ejecutar el job
     */
    protected function process(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            $this->log('warning', 'Usuario no encontrado para enviar email de bienvenida', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Enviar email de bienvenida usando NotificationService
        $sent = NotificationService::sendEmail(
            $user->email,
            'Bienvenido a '.config('app.name'),
            'emails.welcome',
            [
                'user' => $user,
                'name' => $user->name,
                'app_name' => config('app.name'),
            ],
            false // Ya estamos en cola, no necesitamos otra cola
        );

        if ($sent) {
            $this->log('info', 'Email de bienvenida enviado exitosamente', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        } else {
            throw new \RuntimeException("No se pudo enviar el email de bienvenida al usuario {$user->id}");
        }
    }
}

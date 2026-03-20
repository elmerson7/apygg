<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiKeyCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $keyName,
        public readonly string $createdAt,
        public readonly ?string $expiresAt = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Nueva API Key creada - ' . config('app.name'))
            ->greeting('Hola ' . $notifiable->name . '!')
            ->line('Se ha creado una nueva API Key para tu cuenta.')
            ->line('Nombre: ' . $this->keyName)
            ->line('Creada: ' . $this->createdAt);

        if ($this->expiresAt) {
            $mail->line('Expira: ' . $this->expiresAt);
        }

        return $mail
            ->line('Recuerda guardar esta clave en un lugar seguro.')
            ->salutation('Saludos, el equipo de ' . config('app.name'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'api_key_created',
            'key_name' => $this->keyName,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
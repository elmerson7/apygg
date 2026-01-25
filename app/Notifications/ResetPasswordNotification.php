<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Token de reset de contraseña
     */
    protected string $token;

    /**
     * URL personalizada para reset (opcional)
     */
    protected ?string $resetUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token, ?string $resetUrl = null)
    {
        $this->token = $token;
        $this->resetUrl = $resetUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Construir URL de reset
        $resetUrl = $this->resetUrl ?? url("/reset-password?token={$this->token}&email=" . urlencode($notifiable->email));

        return (new MailMessage)
            ->subject('Restablecer Contraseña')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Recibiste este email porque solicitaste restablecer tu contraseña.')
            ->action('Restablecer Contraseña', $resetUrl)
            ->line('Este enlace expirará en 60 minutos.')
            ->line('Si no solicitaste este cambio, ignora este email.')
            ->salutation('Saludos, ' . config('app.name'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
            'reset_url' => $this->resetUrl,
        ];
    }
}

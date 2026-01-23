<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Token de reset de contraseña
     *
     * @var string
     */
    protected string $token;

    /**
     * Create a new notification instance.
     *
     * @param string $token Token de reset
     */
    public function __construct(string $token)
    {
        $this->token = $token;
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
        $url = config('app.frontend_url', config('app.url')) . '/reset-password?' . http_build_query([
            'email' => $notifiable->email,
            'token' => $this->token,
        ]);

        return (new MailMessage)
            ->subject('Recuperación de Contraseña')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Recibiste este email porque solicitaste recuperar tu contraseña.')
            ->action('Restablecer Contraseña', $url)
            ->line('Este enlace expirará en ' . config('auth.passwords.users.expire', 60) . ' minutos.')
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
            'email' => $notifiable->email,
        ];
    }
}

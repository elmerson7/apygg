<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public static function mailTemplateKey(): string
    {
        return 'welcome';
    }

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        $this->onQueue(config('mail-system.queue', 'default'));
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
        return (new MailMessage)
            ->subject('Bienvenido a '.config('app.name'))
            ->greeting('¡Hola '.$notifiable->name.'!')
            ->line('Te damos la bienvenida a '.config('app.name').'.')
            ->line('Tu cuenta ha sido creada exitosamente y ya puedes comenzar a usar nuestros servicios.')
            ->line('Si tienes alguna pregunta o necesitas ayuda, no dudes en contactarnos.')
            ->salutation('Saludos, el equipo de '.config('app.name'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'user_id' => $notifiable->id,
        ];
    }
}

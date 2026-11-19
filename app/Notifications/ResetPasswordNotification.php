<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public static function mailTemplateKey(): string
    {
        return 'password_reset';
    }

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
        // Construir URL de reset
        $resetUrl = $this->buildResetUrl($notifiable->email);

        return (new MailMessage)
            ->subject(__('messages.mail.password_reset.subject'))
            ->greeting(__('messages.mail.password_reset.greeting', ['name' => $notifiable->name]))
            ->line(__('messages.mail.password_reset.intro'))
            ->action(__('messages.mail.password_reset.action'), $resetUrl)
            ->line(__('messages.mail.password_reset.expires'))
            ->line(__('messages.mail.password_reset.ignore'))
            ->salutation(__('messages.mail.password_reset.salutation', ['app' => config('app.name')]));
    }

    /**
     * URL del enlace: la personalizada (ya validada contra la lista blanca) o
     * la por defecto. El token y el email van SIEMPRE en la query.
     */
    protected function buildResetUrl(string $email): string
    {
        $base = $this->resetUrl ?? url('/reset-password');
        $query = http_build_query(['token' => $this->token, 'email' => $email]);

        // El fragmento (#/ruta de una SPA) debe ir después de la query
        $fragment = '';
        if (($pos = strpos($base, '#')) !== false) {
            $fragment = substr($base, $pos);
            $base = substr($base, 0, $pos);
        }

        return $base.(str_contains($base, '?') ? '&' : '?').$query.$fragment;
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

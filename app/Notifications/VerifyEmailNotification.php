<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo con el enlace de verificación (token firmado con caducidad, ver EmailVerificationService).
 */
class VerifyEmailNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public static function mailTemplateKey(): string
    {
        return 'email_verification';
    }

    public function __construct(protected string $url, protected int $expireMinutes)
    {
        $this->onQueue(config('mail-system.queue', 'default'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.mail.email_verification.subject'))
            ->greeting(__('messages.mail.email_verification.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('messages.mail.email_verification.intro'))
            ->action(__('messages.mail.email_verification.action'), $this->url)
            ->line(__('messages.mail.email_verification.expires', ['minutes' => $this->expireMinutes]))
            ->line(__('messages.mail.email_verification.ignore'))
            ->salutation(__('messages.mail.email_verification.salutation', ['app' => config('app.name')]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['expires_in_minutes' => $this->expireMinutes];
    }
}

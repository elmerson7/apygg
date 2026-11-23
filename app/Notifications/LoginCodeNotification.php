<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo con el código de un solo uso para entrar sin contraseña (módulo `otp-login`).
 */
class LoginCodeNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public static function mailTemplateKey(): string
    {
        return 'login_code';
    }

    public function __construct(protected string $code, protected int $expireMinutes)
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
            ->subject(__('otp-login.mail.subject'))
            ->greeting(__('otp-login.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('otp-login.mail.intro'))
            ->line('**'.$this->code.'**')
            ->line(__('otp-login.mail.expires', ['minutes' => $this->expireMinutes]))
            ->line(__('otp-login.mail.ignore'))
            ->salutation(__('otp-login.mail.salutation', ['app' => config('app.name')]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['expires_in_minutes' => $this->expireMinutes];
    }
}

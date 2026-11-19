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
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject(__('messages.mail.welcome.subject', ['app' => $app]))
            ->greeting(__('messages.mail.welcome.greeting', ['name' => $notifiable->name]))
            ->line(__('messages.mail.welcome.intro', ['app' => $app]))
            ->line(__('messages.mail.welcome.body'))
            ->line(__('messages.mail.welcome.help'))
            ->salutation(__('messages.mail.welcome.salutation', ['app' => $app]));
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

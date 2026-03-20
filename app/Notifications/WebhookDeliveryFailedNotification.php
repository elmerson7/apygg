<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebhookDeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $webhookName,
        public readonly string $event,
        public readonly string $endpoint,
        public readonly string $error,
        public readonly int $attempts,
        public readonly string $failedAt
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('⚠️ Fallo en Webhook - ' . config('app.name'))
            ->greeting('Alerta de Webhook')
            ->line('Se ha producido un fallo en la entrega de un webhook.')
            ->line('Webhook: ' . $this->webhookName)
            ->line('Evento: ' . $this->event)
            ->line('Endpoint: ' . $this->endpoint)
            ->line('Intentos: ' . $this->attempts)
            ->line('Error: ' . $this->error)
            ->line('Fecha: ' . $this->failedAt)
            ->action('Revisar en panel', url('/admin/webhooks'))
            ->salutation('Saludos, el equipo de ' . config('app.name'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'webhook_failed',
            'webhook_name' => $this->webhookName,
            'event' => $this->event,
            'endpoint' => $this->endpoint,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'failed_at' => $this->failedAt,
        ];
    }
}
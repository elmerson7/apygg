<?php

namespace App\Integrations\Sms;

use App\Contracts\SmsSenderInterface;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\RetryPolicy;
use Illuminate\Notifications\Notification;

/**
 * Canal `sms` de las Notifications de Laravel.
 *
 *   - La notificación define `via() => ['sms']` y `toSms($notifiable): string|SmsMessage`.
 *   - El destino sale de `$notifiable->routeNotificationFor('sms')` (o `Notification::route('sms', '+34...')`).
 *   - Si la notificación implementa `ShouldQueue`, ya corre en cola: el canal envía con reintento corto
 *     solo ante errores reintentables; un error permanente sube y la cola lo marca como fallido.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSenderInterface $sender) {}

    public function send(mixed $notifiable, Notification $notification): ?SmsResult
    {
        if (! method_exists($notification, 'toSms')) {
            throw new PermanentIntegrationError('La notificación no define toSms()', 'sms');
        }

        $message = $notification->toSms($notifiable);

        if (is_string($message)) {
            $to = $notifiable->routeNotificationFor('sms', $notification);

            if (! is_string($to) || $to === '') {
                return null; // sin teléfono: no hay a quién enviar
            }

            $message = new SmsMessage($to, $message);
        }

        return RetryPolicy::fromConfig()->run(fn () => $this->sender->send($message));
    }
}

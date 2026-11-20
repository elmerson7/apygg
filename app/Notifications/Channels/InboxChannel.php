<?php

namespace App\Notifications\Channels;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\TypedNotification;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * Canal `inbox` (el `database` de la configuración): guarda el aviso en la bandeja in-app del usuario,
 * en el tenant activo. Idempotente por (notificación, usuario): un reintento no duplica el aviso.
 */
class InboxChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof TypedNotification || ! $notifiable instanceof User) {
            throw new InvalidArgumentException('InboxChannel solo guarda TypedNotification de usuarios');
        }

        $content = $notification->toInbox($notifiable);

        InboxNotification::query()->firstOrCreate(
            ['notification_id' => (string) $notification->id, 'user_id' => $notifiable->getKey()],
            ['type' => $content['type'], 'title' => $content['title'], 'body' => $content['body'], 'data' => $content['data']],
        );
    }
}

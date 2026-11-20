<?php

namespace App\Notifications\Channels;

use App\Contracts\DeviceServiceInterface;
use App\Contracts\PushTransportInterface;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\PushTokenInvalidError;
use App\Integrations\Push\PushMessage;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\TypedNotification;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * Canal `push`: envía a TODOS los dispositivos del usuario con el transporte elegido por `PUSH_MODE`.
 * Un token que el proveedor da por inexistente se borra. Si ningún dispositivo recibió el mensaje y hubo un
 * error reintentable, se relanza para que la cola reintente; si al menos uno lo recibió, se da por enviado.
 *
 * No guarda dependencias (el `ChannelManager` cachea la instancia): las resuelve al enviar.
 */
class PushChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof TypedNotification || ! $notifiable instanceof User) {
            throw new InvalidArgumentException('PushChannel solo envía TypedNotification a usuarios');
        }

        app()->call([$this, 'deliver'], ['user' => $notifiable, 'notification' => $notification]);
    }

    public function deliver(User $user, TypedNotification $notification, PushTransportInterface $transport, DeviceServiceInterface $devices): void
    {
        $content = $notification->toPush($user);
        $delivered = 0;
        $first = null;

        foreach (UserDevice::query()->where('user_id', $user->getKey())->get() as $device) {
            try {
                $transport->send(new PushMessage($device->token, $content['title'], $content['body'], $content['data']));
                $delivered++;
            } catch (PushTokenInvalidError) {
                $devices->forgetToken($device->token);
            } catch (IntegrationException $e) {
                $first ??= $e;
            }
        }

        if ($delivered === 0 && $first !== null) {
            throw $first;
        }
    }
}

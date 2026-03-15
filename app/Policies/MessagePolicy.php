<?php

namespace App\Policies;

use App\Models\User;
use App\Services\LogService;

/**
 * MessagePolicy
 *
 * Policy para autorización de acciones sobre mensajes.
 * Solo el sender_id o receiver_id pueden acceder al mensaje.
 */
class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, \App\Models\Message $message): bool
    {
        return $user->id === $message->sender_id || $user->id === $message->receiver_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, \App\Models\Message $message): bool
    {
        return $user->id === $message->sender_id;
    }

    public function delete(User $user, \App\Models\Message $message): bool
    {
        $allowed = $user->id === $message->sender_id || $user->hasPermission('messages.delete');

        if ($allowed) {
            LogService::info('Intento de eliminar mensaje autorizado', [
                'user_id' => $user->id,
                'message_id' => $message->id,
            ], 'security');
        }

        return $allowed;
    }
}

<?php

namespace App\Policies;

use App\Models\Gift;
use App\Models\User;
use App\Services\LogService;

/**
 * GiftPolicy
 *
 * Policy para autorización de envío/consulta de gifts.
 */
class GiftPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Gift $gift): bool
    {
        return $user->id === $gift->sender_id || $user->id === $gift->receiver_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Gift $gift): bool
    {
        $allowed = $user->hasPermission('gifts.delete');

        if ($allowed) {
            LogService::info('Intento de eliminar gift autorizado', [
                'user_id' => $user->id,
                'gift_id' => $gift->id,
            ], 'security');
        }

        return $allowed;
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserMatch;
use App\Services\LogService;

/**
 * MatchPolicy
 *
 * Policy para autorización de acciones sobre matches.
 * Solo los participantes del match pueden acceder.
 */
class MatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserMatch $match): bool
    {
        return $user->id === $match->user_id || $user->id === $match->target_id;
    }

    public function delete(User $user, UserMatch $match): bool
    {
        $allowed = $user->id === $match->user_id || $user->id === $match->target_id;

        if ($allowed) {
            LogService::info('Intento de eliminar match autorizado', [
                'user_id' => $user->id,
                'match_id' => $match->id,
            ], 'security');
        }

        return $allowed;
    }
}

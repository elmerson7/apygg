<?php

namespace App\Policies;

use App\Models\User;
use App\Services\LogService;

/**
 * AdminPolicy
 *
 * Policy para acciones administrativas.
 * Solo usuarios con rol admin o permiso específico.
 */
class AdminPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    public function accessPanel(User $user): bool
    {
        $allowed = $user->hasPermission('admin.access');

        if (! $allowed) {
            LogService::warning('Intento de acceso al panel admin denegado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    public function manageUsers(User $user): bool
    {
        return $user->hasPermission('admin.users');
    }

    public function manageReports(User $user): bool
    {
        return $user->hasPermission('admin.reports');
    }
}

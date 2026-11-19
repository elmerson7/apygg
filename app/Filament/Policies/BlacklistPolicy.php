<?php

namespace App\Filament\Policies;

use App\Models\User;

/** Lista negra (`security.blacklist`, como /security/blacklist): ver, bloquear y desbloquear. */
class BlacklistPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'security.blacklist';
    }

    public function create(User $user): bool
    {
        return $user->can('security.blacklist');
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return $user->can('security.blacklist');
    }
}

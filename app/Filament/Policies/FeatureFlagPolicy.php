<?php

namespace App\Filament\Policies;

use App\Models\User;

/** Feature flags: ver `feature-flags.read`; crear, editar y restablecer `feature-flags.manage` (como /admin/feature-flags). */
class FeatureFlagPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'feature-flags.read';
    }

    public function create(User $user): bool
    {
        return $user->can('feature-flags.manage');
    }

    public function update(User $user, mixed $record = null): bool
    {
        return $user->can('feature-flags.manage');
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return $user->can('feature-flags.manage');
    }
}

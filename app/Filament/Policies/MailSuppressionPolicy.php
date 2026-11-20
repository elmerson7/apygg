<?php

namespace App\Filament\Policies;

use App\Models\User;

/** Lista de supresión de correo (`mail.suppressions`): ver, añadir a mano y quitar. */
class MailSuppressionPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'mail.suppressions';
    }

    public function create(User $user): bool
    {
        return $user->can('mail.suppressions');
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return $user->can('mail.suppressions');
    }
}

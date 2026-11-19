<?php

namespace App\Filament\Policies;

use App\Models\User;

/**
 * Cuentas de envío y plantillas (`mail.manage`, como /admin/mail/accounts y /templates).
 * Se pueden ver; `update` habilita pausar/reanudar una plantilla (las cuentas no se editan aquí).
 */
class MailManagePolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'mail.manage';
    }

    public function update(User $user, mixed $record = null): bool
    {
        return $user->can('mail.manage');
    }
}

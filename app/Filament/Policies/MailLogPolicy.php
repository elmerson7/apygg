<?php

namespace App\Filament\Policies;

/** Registro de envíos: solo lectura (`mail.logs`, como GET /admin/mail/logs). */
class MailLogPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'mail.logs';
    }
}

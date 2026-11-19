<?php

namespace App\Filament\Policies;

/** Logs de actividad, API y seguridad: solo lectura (`system.logs`, como GET /admin/logs/*). */
class SystemLogPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'system.logs';
    }
}

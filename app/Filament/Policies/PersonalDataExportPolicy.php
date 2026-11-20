<?php

namespace App\Filament\Policies;

/** Exportaciones de datos personales (privacidad): solo lectura para soporte (`users.export`). */
class PersonalDataExportPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'users.export';
    }
}

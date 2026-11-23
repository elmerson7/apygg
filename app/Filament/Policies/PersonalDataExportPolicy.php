<?php

namespace App\Filament\Policies;

/**
 * Exportaciones de datos personales (privacidad): solo lectura para soporte.
 * Usa `users.read` (núcleo), no `users.export` (módulo export-import): privacy no depende de otro módulo opcional.
 */
class PersonalDataExportPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'users.read';
    }
}

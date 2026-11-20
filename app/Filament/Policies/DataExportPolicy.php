<?php

namespace App\Filament\Policies;

/** Exportaciones de datos de plataforma: solo lectura (`users.export`). */
class DataExportPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'users.export';
    }
}

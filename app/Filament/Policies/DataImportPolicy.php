<?php

namespace App\Filament\Policies;

/** Importaciones de datos de plataforma: solo lectura (`users.import`). */
class DataImportPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'users.import';
    }
}

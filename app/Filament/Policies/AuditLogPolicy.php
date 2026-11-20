<?php

namespace App\Filament\Policies;

/** Auditoría de la plataforma: solo lectura (`audit.read`, como GET /admin/audit). */
class AuditLogPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'audit.read';
    }
}

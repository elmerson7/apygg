<?php

namespace App\Filament\Policies;

use App\Models\User;

/** Empresas (tenants): ver con `tenants.read`; suspender/reactivar con `tenants.manage`. No se crean ni borran desde aquí. */
class TenantPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'tenants.read';
    }

    public function update(User $user, mixed $record = null): bool
    {
        return $user->can('tenants.manage');
    }
}

<?php

namespace App\Services\I18n;

use App\Contracts\LocaleSettingsServiceInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;

class LocaleSettingsService implements LocaleSettingsServiceInterface
{
    public function updateUser(User $user, array $data): User
    {
        $user->update(array_intersect_key($data, array_flip(['locale', 'timezone'])));

        return $user->refresh();
    }

    public function updateTenant(Tenant $tenant, User $actor, array $data): Tenant
    {
        // El `permission:` de la ruta no basta con equipos apagados: manda el rol del actor EN este tenant.
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-settings.manage');

        $tenant->update(array_intersect_key($data, array_flip(['locale', 'timezone', 'currency'])));

        return $tenant->refresh();
    }
}

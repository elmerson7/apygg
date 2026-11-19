<?php

namespace App\Services\I18n;

use App\Contracts\LocaleSettingsServiceInterface;
use App\Models\Tenant;
use App\Models\User;

class LocaleSettingsService implements LocaleSettingsServiceInterface
{
    public function updateUser(User $user, array $data): User
    {
        $user->update(array_intersect_key($data, array_flip(['locale', 'timezone'])));

        return $user->refresh();
    }

    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        $tenant->update(array_intersect_key($data, array_flip(['locale', 'timezone', 'currency'])));

        return $tenant->refresh();
    }
}

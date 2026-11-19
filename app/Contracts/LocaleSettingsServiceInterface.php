<?php

namespace App\Contracts;

use App\Models\Tenant;
use App\Models\User;

interface LocaleSettingsServiceInterface
{
    /**
     * Guarda idioma y zona horaria del usuario (null = heredar).
     *
     * @param  array<string, mixed>  $data  Ya validado: `locale`, `timezone`
     */
    public function updateUser(User $user, array $data): User;

    /**
     * Guarda idioma, zona horaria y moneda del tenant (null = heredar de la instalación).
     *
     * @param  array<string, mixed>  $data  Ya validado: `locale`, `timezone`, `currency`
     */
    public function updateTenant(Tenant $tenant, array $data): Tenant;
}

<?php

namespace App\Contracts;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

interface TenantOnboardingServiceInterface
{
    /**
     * Alta de una empresa por un usuario autenticado, que queda como dueño (membresía activa + rol).
     *
     * @param  array{name: string, slug: string}  $data
     */
    public function createTenant(User $owner, array $data): Tenant;

    /**
     * Empresas del usuario (membresía activa o suspendida) con su rol.
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsOf(User $user): Collection;
}

<?php

namespace App\Contracts;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TenantMemberServiceInterface
{
    /**
     * Miembros del tenant (SQL sobre `tenant_user`, paginado).
     *
     * @param  array<string, mixed>  $filters  status, role, search
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(Tenant $tenant, array $filters, int $perPage): LengthAwarePaginator;

    public function changeRole(Tenant $tenant, User $actor, int $userId, string $role): User;

    public function suspend(Tenant $tenant, User $actor, int $userId): User;

    public function reactivate(Tenant $tenant, User $actor, int $userId): User;

    public function remove(Tenant $tenant, User $actor, int $userId): void;
}

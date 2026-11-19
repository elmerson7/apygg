<?php

namespace App\Services;

use App\Contracts\TenantMemberServiceInterface;
use App\Enums\TenantMembershipStatus;
use App\Exceptions\Tenancy\TenantOnboardingException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Miembros DENTRO de un tenant (docs/tenant-onboarding.md). El tenant llega del contexto (middleware
 * `tenant`), nunca del cuerpo. Reglas, todas dentro de una transacción con el tenant bloqueado
 * (`FOR UPDATE`) para que dos cambios simultáneos no dejen la empresa sin dueño:
 *
 * - Nadie toca a un miembro de rango superior al suyo ni asigna un rol superior al suyo.
 * - No se puede quitar, degradar ni suspender al último dueño activo.
 * - Un actor que no es miembro (soporte de plataforma con `tenants.access-any`) cuenta con el rango máximo.
 */
class TenantMemberService implements TenantMemberServiceInterface
{
    public function paginate(Tenant $tenant, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->membersQuery($tenant);

        if (! empty($filters['status'])) {
            $query->where('tenant_user.status', $filters['status']);
        }

        if (! empty($filters['role'])) {
            $query->where('tenant_user.role', $filters['role']);
        }

        if (! empty($filters['search'])) {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('users.name', 'ILIKE', $like)->orWhere('users.email', 'ILIKE', $like));
        }

        return $query->orderBy('users.name')->orderBy('users.id')->paginate($perPage);
    }

    public function changeRole(Tenant $tenant, User $actor, int $userId, string $role): User
    {
        $this->mutate($tenant, $actor, $userId, function (object $target, int $actorRank) use ($tenant, $role): void {
            if (TenantRoles::rank($role) > $actorRank) {
                throw TenantOnboardingException::roleInsufficient();
            }

            if ($role !== TenantRoles::owner()) {
                $this->guardLastOwner($tenant, $target);
            }

            DB::table('tenant_user')->where('id', $target->id)->update(['role' => $role, 'updated_at' => now()]);
        });

        $member = User::query()->findOrFail($userId);
        TenantRoles::sync($member, $tenant, $role);

        return $this->find($tenant, $userId);
    }

    public function suspend(Tenant $tenant, User $actor, int $userId): User
    {
        if ($actor->getKey() === $userId) {
            throw TenantOnboardingException::cannotSuspendSelf();
        }

        $this->mutate($tenant, $actor, $userId, function (object $target) use ($tenant): void {
            $this->guardLastOwner($tenant, $target);

            DB::table('tenant_user')->where('id', $target->id)->update([
                'status' => TenantMembershipStatus::Suspended->value,
                'updated_at' => now(),
            ]);
        });

        return $this->find($tenant, $userId);
    }

    public function reactivate(Tenant $tenant, User $actor, int $userId): User
    {
        $this->mutate($tenant, $actor, $userId, function (object $target): void {
            DB::table('tenant_user')->where('id', $target->id)->update([
                'status' => TenantMembershipStatus::Active->value,
                'updated_at' => now(),
            ]);
        });

        return $this->find($tenant, $userId);
    }

    public function remove(Tenant $tenant, User $actor, int $userId): void
    {
        $this->mutate($tenant, $actor, $userId, function (object $target) use ($tenant): void {
            $this->guardLastOwner($tenant, $target);

            DB::table('tenant_user')->where('id', $target->id)->delete();
        });

        $member = User::query()->find($userId);
        if ($member instanceof User) {
            TenantRoles::revoke($member, $tenant);
        }
    }

    /**
     * @param  \Closure(object, int): void  $change  recibe la fila de `tenant_user` del objetivo y el rango del actor
     */
    private function mutate(Tenant $tenant, User $actor, int $userId, \Closure $change): void
    {
        DB::transaction(function () use ($tenant, $actor, $userId, $change): void {
            // Serializa los cambios de miembros de ESTE tenant (la salvaguarda del último dueño depende de ello).
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            $target = DB::table('tenant_user')
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw TenantOnboardingException::memberNotFound();
            }

            $actorRank = TenantRoles::actorRankFor($tenant, $actor, 'tenant-members.manage');

            if (TenantRoles::rank((string) $target->role) > $actorRank) {
                throw TenantOnboardingException::roleInsufficient();
            }

            $change($target, $actorRank);
        });
    }

    private function guardLastOwner(Tenant $tenant, object $target): void
    {
        if ($target->role !== TenantRoles::owner() || $target->status !== TenantMembershipStatus::Active->value) {
            return;
        }

        $owners = DB::table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('role', TenantRoles::owner())
            ->where('status', TenantMembershipStatus::Active->value)
            ->count();

        if ($owners <= 1) {
            throw TenantOnboardingException::lastOwner();
        }
    }

    private function find(Tenant $tenant, int $userId): User
    {
        return $this->membersQuery($tenant)->where('users.id', $userId)->firstOrFail();
    }

    /**
     * @return Builder<User>
     */
    private function membersQuery(Tenant $tenant): Builder
    {
        return User::query()
            ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
            ->where('tenant_user.tenant_id', $tenant->getKey())
            ->select([
                'users.*',
                'tenant_user.role as tenant_role',
                'tenant_user.status as tenant_status',
                'tenant_user.joined_at as tenant_joined_at',
            ]);
    }
}

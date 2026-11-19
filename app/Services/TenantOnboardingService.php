<?php

namespace App\Services;

use App\Contracts\TenantOnboardingServiceInterface;
use App\Enums\TenantMembershipStatus;
use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\TenantOnboardingException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta de empresas (signup). Una sola transacción: tenant + membresía de dueño + rol de Spatie.
 * Nada de cobros ni planes: `trial_ends_at` es solo una fecha (la facturación es otra fase).
 */
class TenantOnboardingService implements TenantOnboardingServiceInterface
{
    public function createTenant(User $owner, array $data): Tenant
    {
        $config = (array) config('multi-tenancy.onboarding.signup', []);

        if (! ($config['enabled'] ?? false)) {
            throw TenantOnboardingException::signupDisabled();
        }

        $max = (int) ($config['max_tenants_per_user'] ?? 0);
        if ($max > 0 && $this->ownedCount($owner) >= $max) {
            throw TenantOnboardingException::tenantLimitReached();
        }

        $trialDays = $config['trial_days'] ?? null;

        try {
            $tenant = DB::transaction(function () use ($owner, $data, $config, $trialDays): Tenant {
                $tenant = Tenant::create([
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'status' => ($config['require_approval'] ?? false) ? TenantStatus::Pending : TenantStatus::Active,
                    'trial_ends_at' => is_int($trialDays) && $trialDays > 0 ? now()->addDays($trialDays) : null,
                ]);

                $role = TenantRoles::owner();

                $tenant->users()->attach($owner->getKey(), [
                    'status' => TenantMembershipStatus::Active->value,
                    'role' => $role,
                    'joined_at' => now(),
                ]);

                TenantRoles::sync($owner, $tenant, $role);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos altas del mismo slug a la vez: la restricción única de BD decide; el perdedor recibe 422.
            throw ValidationException::withMessages(['slug' => ['Ese identificador ya está en uso.']]);
        }

        LogService::info('tenancy.signup: empresa creada', [
            'event_type' => 'tenancy.signup',
            'tenant_id' => $tenant->getKey(),
            'user_id' => $owner->getKey(),
            'status' => $tenant->status->value,
        ], 'activity');

        return $tenant;
    }

    public function tenantsOf(User $user): Collection
    {
        return $user->tenants()
            ->whereIn('tenant_user.status', [TenantMembershipStatus::Active->value, TenantMembershipStatus::Suspended->value])
            ->orderBy('tenants.name')
            ->get();
    }

    private function ownedCount(User $user): int
    {
        return (int) DB::table('tenant_user')
            ->where('user_id', $user->getKey())
            ->where('role', TenantRoles::owner())
            ->count();
    }
}

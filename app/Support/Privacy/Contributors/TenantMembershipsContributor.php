<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class TenantMembershipsContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'tenant_memberships';
    }

    public function export(User $user): array
    {
        $rows = DB::table('tenant_user')
            ->join('tenants', 'tenants.id', '=', 'tenant_user.tenant_id')
            ->where('tenant_user.user_id', $user->getKey())
            ->orderBy('tenant_user.id')
            ->get(['tenants.id as tenant_id', 'tenants.name', 'tenants.slug', 'tenant_user.role', 'tenant_user.status', 'tenant_user.joined_at']);

        return ['memberships' => $rows->all()];
    }

    public function erase(User $user): void
    {
        DB::table('tenant_user')->where('user_id', $user->getKey())->delete();
        DB::table('model_has_roles')->where('model_id', $user->getKey())->where('model_type', $user->getMorphClass())->delete();
        DB::table('model_has_permissions')->where('model_id', $user->getKey())->where('model_type', $user->getMorphClass())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

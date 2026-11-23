<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Contracts\RoleServiceInterface;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Failure;
use App\Models\Role;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** Como POST /roles: crear con RoleService; los permisos exigen roles.manage-permissions y que los tengas. */
    protected function handleRecordCreation(array $data): Model
    {
        $role = Failure::guard(function () use ($data): Role {
            Gate::authorize('create', Role::class);

            $permissionIds = array_values(array_map('intval', $data['permission_ids'] ?? []));
            unset($data['permission_ids']);

            $service = app(RoleServiceInterface::class);

            if ($permissionIds !== []) {
                Gate::authorize('assignPermission', new Role);
                $service->assertCanGrantPermissions(auth('web')->user() ?? new User, $permissionIds);
            }

            $role = $service->create($data);

            if ($permissionIds !== []) {
                $service->syncPermissions((string) $role->id, $permissionIds);
            }

            return $role;
        });

        if (! $role instanceof Role) {
            $this->halt();
        }

        return $role;
    }
}

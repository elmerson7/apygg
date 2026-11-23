<?php

namespace App\Filament\Resources\Users\Pages;

use App\Contracts\UserServiceInterface;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Failure;
use App\Models\Role;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Crea con UserService (perfil, roles y eventos igual que POST /users). Cada rol pasa por
     * RolePolicy::assign aunque el formulario ya solo ofrezca los asignables: nadie concede
     * un rol con permisos que no tiene.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Failure::guard(function () use ($data): User {
            Gate::authorize('create', User::class);

            $roleIds = array_values(array_map('intval', $data['role_ids'] ?? []));
            unset($data['role_ids']);

            foreach (Role::whereIn('id', $roleIds)->get() as $role) {
                Gate::authorize('assign', $role);
            }

            return app(UserServiceInterface::class)->create($data, $roleIds);
        });

        if (! $user instanceof User) {
            throw new Halt;
        }

        return $user;
    }
}
